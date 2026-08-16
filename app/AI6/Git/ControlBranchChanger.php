<?php

namespace App\AI6\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\ControlBranchAuditEntry;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\PendingControlBinding;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class ControlBranchChanger
{
    public function __construct(
        private ControlOperationConfiguration $configuration,
        private ProjectOperationLease $lease,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private CanonicalJson $canonicalJson,
        private ControlRemoteProbe $remoteProbe,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $operation->refresh();
        $this->assertType($operation);

        return match ($operation->phase) {
            ControlOperationPhase::CLAIMED => $this->probe($operation, $attemptToken),
            ControlOperationPhase::REMOTE_PROBED => $this->publish($operation, $attemptToken),
            ControlOperationPhase::ATTEMPT_COMPLETED => true,
            default => throw new RuntimeException('The control-branch operation has an incompatible phase.'),
        };
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
        $project = $operation->project()->firstOrFail();
        $effect = $this->canonicalJson->normalizeAndEncode([
            'control_binding_version' => $project->control_binding_version,
            'control_branch' => $project->control_branch,
            'control_generation' => $project->control_generation,
            'control_oid' => $project->control_oid,
            'pending_control_oid' => $project->pending_control_oid,
            'pending_control_operation_id' => $project->pending_control_operation_id,
            'pending_control_ref' => $project->pending_control_ref,
        ]);
        $effectHash = hash('sha256', "AI6-CONTROL-BRANCH-EFFECT-V1\0".$effect);
        $snapshot = $this->canonicalJson->normalizeAndEncode([
            'control_operation_id' => $operation->id,
            'deviation' => $deviation,
            'effect_hash' => $effectHash,
            'project_id' => $operation->project_id,
        ]);

        return [
            'text' => 'Der atomare Control-Branch-Publish konnte nicht sicher abgeschlossen werden.',
            'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$snapshot),
            'effect_hash' => $effectHash,
        ];
    }

    private function probe(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertExecutionContract($operation, $project, $parameters);
        $this->assertAuthorizationCurrent($operation, $actor, $project);

        try {
            $targetOid = $this->remoteProbe->resolve(
                $project,
                $parameters['new_control_ref'],
                new RedactionContext((string) $project->getKey(), $operation->id, 'control-branch-probe'),
            );
        } catch (ControlRemoteRefUnresolved) {
            throw new ControlOperationTerminalConflict(
                'remote_ref_unresolved',
                'Der neue Control-Branch konnte nicht eindeutig aufgelöst werden.',
            );
        }

        $updated = ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->where('state', ControlOperationState::RUNNING)
            ->where('phase', ControlOperationPhase::CLAIMED)
            ->whereNull('target_control_oid')
            ->update([
                'target_control_oid' => $targetOid,
                'phase' => ControlOperationPhase::REMOTE_PROBED,
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);
        if ($updated !== 1) {
            if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                throw new ControlOperationRetryableConflict(
                    'fencing_conflict',
                    'The remote-probe result lost its operation-attempt ownership.',
                );
            }

            throw new RuntimeException('The remote-probe result could not be persisted exactly once.');
        }

        return false;
    }

    private function publish(ControlOperation $operation, int $attemptToken): bool
    {
        $parameters = $this->parameters($operation);
        $targetOid = $operation->target_control_oid;
        if ($targetOid === null || preg_match('/\A[0-9a-f]{64}\z/D', $targetOid) !== 1) {
            throw new ControlOperationTerminalConflict(
                'remote_probe_binding_invalid',
                'Der gespeicherte Ziel-OID des Control-Branch-Wechsels ist ungültig.',
            );
        }

        DB::transaction(function () use ($operation, $attemptToken, $parameters, $targetOid): void {
            $project = $operation->project()->firstOrFail();
            $actor = $operation->actor()->firstOrFail();
            $this->assertExecutionContract($operation, $project, $parameters);
            $this->assertAuthorizationCurrent($operation, $actor, $project);

            $updated = Project::query()
                ->whereKey($operation->project_id)
                ->where('operation_lock_operation_id', $operation->id)
                ->where('operation_lock_attempt_token', $attemptToken)
                ->whereNull('active_run_id')
                ->where('control_branch', $parameters['old_control_ref'])
                ->where('control_binding_version', $parameters['expected_binding_version'])
                ->where(function (Builder $query) use ($parameters): void {
                    $query->where(function (Builder $active) use ($parameters): void {
                        $active->where('control_oid', $parameters['old_control_oid'])
                            ->whereNull('pending_control_ref')
                            ->whereNull('pending_control_oid')
                            ->whereNull('pending_control_operation_id');
                    })->orWhere(function (Builder $pending) use ($parameters): void {
                        $pending->whereNull('control_oid')
                            ->where('pending_control_ref', $parameters['old_control_ref'])
                            ->where('pending_control_oid', $parameters['old_control_oid']);
                    });
                })
                ->update([
                    'control_branch' => $parameters['new_control_ref'],
                    'control_oid' => null,
                    'pending_control_ref' => $parameters['new_control_ref'],
                    'pending_control_oid' => $targetOid,
                    'pending_control_operation_id' => $operation->id,
                    'control_binding_version' => DB::raw('control_binding_version + 1'),
                    'control_generation' => DB::raw('control_generation + 1'),
                    'updated_at' => Date::now(),
                ]);
            if ($updated !== 1) {
                if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                    throw new ControlOperationRetryableConflict(
                        'fencing_conflict',
                        'The control-branch publish lost its operation-attempt ownership.',
                    );
                }

                throw new ControlOperationTerminalConflict(
                    'publish_conflict',
                    'Der Control-Branch-Publish steht in Konflikt mit dem aktuellen Projektzustand.',
                );
            }

            ControlBranchAuditEntry::query()->create([
                'project_id' => $operation->project_id,
                'control_operation_id' => $operation->id,
                'actor_id' => $operation->actor_id,
                'old_branch' => $parameters['old_control_ref'],
                'new_branch' => $parameters['new_control_ref'],
                'old_control_oid' => $parameters['old_control_oid'],
                'new_control_oid' => $targetOid,
                'occurred_at' => Date::now(),
            ]);

            $binding = hash(
                'sha256',
                "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.$targetOid,
            );
            ControlOperationResult::query()->create([
                'control_operation_id' => $operation->id,
                'outcome' => ControlOperationOutcome::SUCCEEDED,
                'result_binding' => $binding,
                'safe_summary' => 'Der Control-Branch wurde autorisiert gewechselt und als ausstehende Bindung veröffentlicht.',
            ]);
            $operationUpdated = ControlOperation::query()
                ->whereKey($operation->id)
                ->where('current_attempt_token', $attemptToken)
                ->where('state', ControlOperationState::RUNNING)
                ->where('phase', ControlOperationPhase::REMOTE_PROBED)
                ->where('target_control_oid', $targetOid)
                ->update([
                    'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                    'state' => ControlOperationState::COMPLETED,
                    'completed_at' => Date::now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => Date::now(),
                ]);
            if ($operationUpdated !== 1) {
                throw new RuntimeException('The control-branch operation lost its terminal compare-and-swap binding.');
            }
            if (! $this->lease->release($operation->id, $operation->project_id, $attemptToken)) {
                throw new RuntimeException('The control-branch operation could not release its terminal project lease.');
            }
        });

        return true;
    }

    /**
     * @return array{old_control_ref: string, old_control_oid: string, expected_binding_version: int, new_control_ref: string}
     */
    private function parameters(ControlOperation $operation): array
    {
        try {
            $decoded = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Control-branch operation parameters are malformed.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Control-branch operation parameters are incomplete.');
        }

        $parameters = $operation->operation_type->parameters($decoded);
        if (! is_string($parameters['old_control_ref'])
            || ! is_string($parameters['old_control_oid'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $parameters['old_control_oid']) !== 1
            || ! is_int($parameters['expected_binding_version'])
            || $parameters['expected_binding_version'] < 0
            || ! is_string($parameters['new_control_ref'])
            || ! in_array($parameters['new_control_ref'], $this->configuration->managedRefAllowlist, true)
            || hash_equals($parameters['old_control_ref'], $parameters['new_control_ref'])
            || ! hash_equals($operation->operation_parameters_jcs, $this->canonicalJson->normalizeAndEncode($parameters))) {
            throw new RuntimeException('Control-branch operation parameters are not canonical.');
        }

        return $parameters;
    }

    /**
     * @param  array{old_control_ref: string, old_control_oid: string, expected_binding_version: int, new_control_ref: string}  $parameters
     */
    private function assertExecutionContract(
        ControlOperation $operation,
        Project $project,
        array $parameters,
    ): void {
        $pending = PendingControlBinding::fromProject($project);
        $currentOid = $project->control_oid ?? $pending?->oid;
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->remote === null
            || $project->deploy_key_reference === null
            || $project->host_key_fingerprint === null
            || $project->control_branch !== $parameters['old_control_ref']
            || $project->control_binding_version !== $parameters['expected_binding_version']
            || $currentOid !== $parameters['old_control_oid']
            || ($pending !== null && $pending->ref !== $parameters['old_control_ref'])
            || $operation->expected_control_commit !== $parameters['old_control_oid']) {
            throw new ControlOperationTerminalConflict(
                'publish_precondition_changed',
                'Die Ausgangsbindung des Control-Branch-Wechsels ist nicht mehr aktuell.',
            );
        }
    }

    private function assertType(ControlOperation $operation): void
    {
        if ($operation->operation_type !== ControlOperationType::CONTROL_BRANCH_CHANGE) {
            throw new RuntimeException('The operation is not a control-branch operation.');
        }
    }

    private function assertAuthorizationCurrent(
        ControlOperation $operation,
        User $actor,
        Project $project,
    ): void {
        if (! $this->projectPolicy->changeControlBranch($actor, $project)
            || ! $this->authorizationSnapshots->matchesCurrent($operation, $actor, $project)) {
            throw new ControlOperationTerminalConflict(
                'authorization_changed',
                'Die Autorisierung für den Control-Branch-Wechsel ist nicht mehr aktuell.',
            );
        }
    }
}
