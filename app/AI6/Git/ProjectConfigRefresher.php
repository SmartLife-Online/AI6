<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Actions\QueueProjectConfigRefresh;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectConfigurationHasher;
use App\AI6\Projects\ProjectConfigurationParser;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class ProjectConfigRefresher
{
    public function __construct(
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
        private ProjectOperationLease $lease,
        private ProjectPolicy $policy,
        private ControlOperationAuthorizationSnapshot $authorization,
        private CanonicalJson $canonicalJson,
        private ProjectConfigurationParser $parser,
        private ProjectConfigurationHasher $hasher,
        private Redactor $redactor,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $this->assertType($operation);
        if ($operation->phase === ControlOperationPhase::ATTEMPT_COMPLETED) {
            return true;
        }
        if ($operation->phase !== ControlOperationPhase::CLAIMED) {
            throw new RuntimeException('The config-refresh operation has an incompatible phase.');
        }

        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertCurrent($operation, $project);
        if (! $this->policy->refreshConfiguration($actor, $project)
            || ! $this->authorization->matchesCurrent($operation, $actor, $project)) {
            throw new ControlOperationTerminalConflict('authorization_changed', 'Die Autorisierung für den Config-Refresh ist nicht mehr aktuell.');
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The config refresh lost its lease before reading Git.');
        }

        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory((string) $project->project_identifier));
        $context = new RedactionContext((string) $project->getKey(), null, 'project-config');
        $blob = null;
        try {
            $blob = $this->git->readRegularBlob($repository, (string) $operation->expected_control_commit, $parameters['config_path'], $context);
        } catch (ControlOperationTerminalConflict $exception) {
            if ($exception->conflict !== 'refresh_path_missing_or_ambiguous') {
                throw $exception;
            }
        }

        $state = 'absent';
        $hash = null;
        $normalized = null;
        $errors = [];
        $matches = [];
        if ($blob !== null) {
            try {
                $redaction = $this->redactor->redact($blob->content, $context);
            } catch (InvalidRedactionInputException) {
                throw new ControlOperationTerminalConflict('config_blob_not_utf8', 'Der gebundene Config-Blob enthält kein gültiges UTF-8.');
            }
            $matches = array_map(static fn ($match): array => $match->jsonSerialize(), $redaction->matches);
            $parsed = $this->parser->parse($blob->content);
            $errors = array_map(static fn ($error): array => $error->jsonSerialize(), $parsed->errors);
            if ($matches !== []) {
                $errors[] = [
                    'code' => 'config_content_redacted',
                    'field' => 'config',
                    'message' => 'Der Konfigurationsinhalt enthält schützenswerte Werte und kann nicht freigegeben werden.',
                ];
            }
            if ($parsed->valid() && $parsed->configuration !== null && $matches === []) {
                $state = 'valid';
                $normalized = $parsed->configuration->values;
                $hash = $this->hasher->hash($parsed->configuration);
            } else {
                $state = 'invalid';
            }
        }

        $blobSha = $blob?->blobSha;
        DB::transaction(function () use ($operation, $attemptToken, $project, $blobSha, $state, $hash, $normalized, $errors, $matches): void {
            $currentOperation = ControlOperation::query()->findOrFail($operation->id);
            $currentProject = Project::query()->findOrFail($project->getKey());
            $this->assertCurrent($currentOperation, $currentProject);
            if ($currentOperation->state !== ControlOperationState::RUNNING
                || $currentOperation->phase !== ControlOperationPhase::CLAIMED
                || $currentOperation->current_attempt_token !== $attemptToken
                || $currentProject->operation_lock_operation_id !== $currentOperation->id
                || $currentProject->operation_lock_attempt_token !== $attemptToken) {
                throw new ControlOperationRetryableConflict('fencing_conflict', 'The config refresh lost its publish binding.');
            }
            ProjectConfigDraft::query()->create([
                'project_id' => $currentProject->getKey(), 'control_operation_id' => $currentOperation->id,
                'control_commit' => $currentOperation->expected_control_commit, 'blob_sha' => $blobSha,
                'control_generation' => $currentProject->control_generation, 'state' => $state,
                'config_hash' => $hash, 'normalized_config' => $normalized,
                'validation_errors' => $errors, 'redaction_matches' => $matches,
            ]);
            $binding = hash('sha256', "AI6-CONFIG-REFRESH-RESULT-V1\0".$currentOperation->id.$currentOperation->request_hash.$state.($blobSha ?? ''));
            ControlOperationResult::query()->create([
                'control_operation_id' => $currentOperation->id, 'outcome' => ControlOperationOutcome::SUCCEEDED,
                'result_binding' => $binding,
                'safe_summary' => $state === 'absent' ? 'Die Projektkonfiguration fehlt am gebundenen Commit; Serverdefaults sind wirksam.' : 'Der redigierte Projektkonfigurationsentwurf wurde veröffentlicht.',
            ]);
            $now = Date::now();
            $updated = ControlOperation::query()->whereKey($currentOperation->id)
                ->where('current_attempt_token', $attemptToken)->where('state', ControlOperationState::RUNNING)
                ->where('phase', ControlOperationPhase::CLAIMED)->update([
                    'phase' => ControlOperationPhase::ATTEMPT_COMPLETED, 'state' => ControlOperationState::COMPLETED,
                    'completed_at' => $now, 'version' => DB::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($updated !== 1 || ! $this->lease->release($currentOperation->id, $currentOperation->project_id, $attemptToken)) {
                throw new RuntimeException('The config refresh lost its terminal compare-and-swap binding.');
            }
        });

        return true;
    }

    public function activeIntentUnderLock(ControlOperation $operation, int $attemptToken): null
    {
        $this->assertType($operation);

        return null;
    }

    public function cleanupFailedAttempt(ControlOperation $operation, int $attemptToken): void
    {
        $this->assertType($operation);
    }

    /** @return array{text: string, hash: string, effect_hash: string} */
    public function recoveryFinding(ControlOperation $operation, int $attemptToken, string $deviation): array
    {
        $this->assertType($operation);
        $effect = hash('sha256', "AI6-CONFIG-REFRESH-EFFECT-V1\0none");

        return ['text' => 'Der Config-Refresh besitzt keinen wiederherstellbaren Außenstand.',
            'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$operation->id.$effect), 'effect_hash' => $effect];
    }

    /** @return array{config_path: string} */
    private function parameters(ControlOperation $operation): array
    {
        try {
            $decoded = json_decode($operation->operation_parameters_jcs, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Config-refresh parameters are malformed.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Config-refresh parameters are incomplete.');
        }
        $parameters = $operation->operation_type->parameters($decoded);
        if (($parameters['config_path'] ?? null) !== QueueProjectConfigRefresh::CONFIG_PATH
            || ! hash_equals($operation->operation_parameters_jcs, $this->canonicalJson->normalizeAndEncode($parameters))) {
            throw new ControlOperationTerminalConflict('config_refresh_parameters_not_canonical', 'Die Config-Refresh-Parameter sind nicht kanonisch gebunden.');
        }

        return $parameters;
    }

    private function assertCurrent(ControlOperation $operation, Project $project): void
    {
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED || $project->project_identifier === null
            || $project->pending_control_oid !== null || $project->control_oid === null || $operation->expected_control_commit === null
            || ! hash_equals($project->control_oid, $operation->expected_control_commit)) {
            throw new ControlOperationTerminalConflict('config_refresh_control_binding_changed', 'Die aktive Control-Bindung des Config-Refreshs ist nicht mehr aktuell.');
        }
    }

    private function assertType(ControlOperation $operation): void
    {
        if ($operation->operation_type !== ControlOperationType::CONFIG_REFRESH) {
            throw new RuntimeException('The operation is not a config-refresh operation.');
        }
    }
}
