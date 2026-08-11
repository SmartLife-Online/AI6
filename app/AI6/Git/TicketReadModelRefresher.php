<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketInventory;
use App\AI6\Tickets\TicketSourceBlockers;
use App\AI6\Tickets\TicketValidationConfiguration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class TicketReadModelRefresher
{
    public function __construct(
        private ManagedProjectPath $managedPaths,
        private RefreshPathPolicy $refreshPaths,
        private ProjectOperationLease $lease,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private CanonicalJson $canonicalJson,
        private TicketReadModelResultBinding $resultBinding,
        private Redactor $redactor,
        private TicketInventory $ticketInventory,
        private TicketValidationConfiguration $ticketValidation,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $operation->refresh();
        $this->assertType($operation);

        return match ($operation->phase) {
            ControlOperationPhase::CLAIMED => $this->refresh($operation, $attemptToken),
            ControlOperationPhase::ATTEMPT_COMPLETED => true,
            default => throw new RuntimeException('The ticket-refresh operation has an incompatible phase.'),
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
        $effectHash = hash('sha256', "AI6-TICKET-REFRESH-EFFECT-V1\0none");
        $snapshot = $this->canonicalJson->normalizeAndEncode([
            'control_operation_id' => $operation->id,
            'deviation' => $deviation,
            'effect_hash' => $effectHash,
            'project_id' => $operation->project_id,
        ]);

        return [
            'text' => 'Der Read-Model-Refresh besitzt keinen wiederherstellbaren Außenstand.',
            'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$snapshot),
            'effect_hash' => $effectHash,
        ];
    }

    private function refresh(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertExecutionContract($operation, $project, $parameters);
        if (! $this->projectPolicy->refreshReadModel($actor, $project)
            || ! $this->authorizationSnapshots->matchesCurrent($operation, $actor, $project)) {
            throw new ControlOperationTerminalConflict(
                'authorization_changed',
                'Die Autorisierung für den Read-Model-Refresh ist nicht mehr aktuell.',
            );
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The refresh operation lost its lease before reading Git.');
        }

        $repository = $this->managedPaths->assertRepository(
            $this->managedPaths->repositoryDirectory((string) $project->project_identifier),
        );
        $context = new RedactionContext((string) $project->getKey(), null, 'ticket-read-model:'.$parameters['relative_path']);
        $inventory = $this->ticketInventory->inspect(
            $repository,
            (string) $operation->expected_control_commit,
            $parameters['refresh_base_path'],
            $this->ticketValidation->profile,
            $this->ticketValidation->profile,
            $context,
        );
        $blob = $inventory->blobs[$parameters['relative_path']] ?? null;
        $projection = $inventory->projectionFor($parameters['relative_path']);
        if ($blob !== null && $inventory->hasInvalidUtf8($parameters['relative_path'])) {
            throw new ControlOperationTerminalConflict(
                'refresh_blob_not_utf8',
                'Der gebundene Git-Blob enthält kein gültiges UTF-8.',
            );
        }
        if ($blob === null || $projection === null) {
            throw new ControlOperationTerminalConflict(
                'refresh_path_not_ticket_candidate',
                'Der angeforderte Pfad ist am gebundenen Commit kein Ticketkandidat.',
            );
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The refresh operation lost its lease after reading Git.');
        }

        $result = new TicketReadModelRefreshResult(
            $operation->id,
            $operation->project_id,
            $blob->relativePath,
            (string) $operation->expected_control_commit,
            $blob->blobSha,
            $blob->content,
        );
        $this->resultBinding->assertMatches($operation, $project, $parameters, $blob, $result);
        try {
            $redaction = $this->redactor->redact($result->content, $context);
        } catch (InvalidRedactionInputException) {
            throw new ControlOperationTerminalConflict(
                'refresh_blob_not_utf8',
                'Der gebundene Git-Blob enthält kein gültiges UTF-8.',
            );
        }
        $matches = array_map(
            static fn ($match): array => $match->jsonSerialize(),
            $redaction->matches,
        );
        $redactionState = $matches === []
            ? TicketReadModelRedactionState::CLEAR
            : TicketReadModelRedactionState::CONTENT_REDACTED;
        $blockers = $projection->sourceBlockers;
        if ($redactionState === TicketReadModelRedactionState::CONTENT_REDACTED) {
            $blockers[] = TicketSourceBlockers::CONTENT_REDACTED;
        }
        $blockers = TicketSourceBlockers::canonicalize($blockers);
        $editorEligible = $redactionState === TicketReadModelRedactionState::CLEAR
            && array_diff($blockers, [TicketSourceBlockers::INVALID]) === [];
        $approvalEligible = $editorEligible && $projection->state === TicketDocumentState::VALID;

        DB::transaction(function () use (
            $operation,
            $attemptToken,
            $parameters,
            $result,
            $redaction,
            $matches,
            $redactionState,
            $blockers,
            $projection,
            $editorEligible,
            $approvalEligible,
        ): void {
            $currentOperation = ControlOperation::query()->findOrFail($operation->id);
            $currentProject = Project::query()->findOrFail($operation->project_id);
            $this->resultBinding->assertMatches(
                $currentOperation,
                $currentProject,
                $parameters,
                new TicketBlob($result->relativePath, $result->blobSha, $result->content),
                $result,
            );
            $this->assertExecutionContract($currentOperation, $currentProject, $parameters);
            if ($currentOperation->state !== ControlOperationState::RUNNING
                || $currentOperation->phase !== ControlOperationPhase::CLAIMED
                || $currentOperation->current_attempt_token !== $attemptToken
                || $currentProject->operation_lock_operation_id !== $currentOperation->id
                || $currentProject->operation_lock_attempt_token !== $attemptToken) {
                throw new ControlOperationRetryableConflict('fencing_conflict', 'The refresh publish lost its ownership binding.');
            }

            $generatedAt = Date::now();
            TicketReadModel::query()->updateOrCreate(
                [
                    'project_id' => $currentProject->getKey(),
                    'relative_path' => $result->relativePath,
                ],
                [
                    'control_operation_id' => $currentOperation->id,
                    'control_commit' => $result->controlCommit,
                    'blob_sha' => $result->blobSha,
                    'control_generation' => $currentProject->control_generation,
                    'validation_profile' => $projection->profile->value,
                    'document_state' => $projection->state,
                    'ticket_contract_sha256' => $projection->contractHash,
                    'validation_errors' => array_map(
                        static fn ($error): array => $error->jsonSerialize(),
                        $projection->errors,
                    ),
                    'redacted_content' => $redaction->text,
                    'redaction_state' => $redactionState,
                    'redaction_matches' => $matches,
                    'source_blockers' => $blockers,
                    'approval_editor_eligible' => false,
                    'editor_eligible' => $editorEligible,
                    'approval_eligible' => $approvalEligible,
                    'generated_at' => $generatedAt,
                ],
            );

            $binding = hash(
                'sha256',
                "AI6-TICKET-REFRESH-RESULT-V1\0".$currentOperation->id.$currentOperation->request_hash.$result->controlCommit.$result->blobSha,
            );
            $operationResult = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $currentOperation->id],
                [
                    'outcome' => ControlOperationOutcome::SUCCEEDED,
                    'result_binding' => $binding,
                    'safe_summary' => 'Das blobgebundene, redigierte Read Model wurde veröffentlicht.',
                ],
            );
            if ($operationResult->outcome !== ControlOperationOutcome::SUCCEEDED
                || ! hash_equals($operationResult->result_binding, $binding)) {
                throw new RuntimeException('The existing refresh result has a different provenance binding.');
            }

            $updated = ControlOperation::query()
                ->whereKey($currentOperation->id)
                ->where('current_attempt_token', $attemptToken)
                ->where('state', ControlOperationState::RUNNING)
                ->where('phase', ControlOperationPhase::CLAIMED)
                ->update([
                    'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                    'state' => ControlOperationState::COMPLETED,
                    'completed_at' => $generatedAt,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $generatedAt,
                ]);
            if ($updated !== 1 || ! $this->lease->release($currentOperation->id, $currentOperation->project_id, $attemptToken)) {
                throw new RuntimeException('The refresh operation lost its terminal compare-and-swap binding.');
            }
        });

        return true;
    }

    /** @return array{refresh_base_path: string, relative_path: string} */
    private function parameters(ControlOperation $operation): array
    {
        try {
            $decoded = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Ticket-refresh operation parameters are malformed.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Ticket-refresh operation parameters are incomplete.');
        }

        $parameters = $operation->operation_type->parameters($decoded);
        if (! is_string($parameters['refresh_base_path'])
            || ! is_string($parameters['relative_path'])
            || ! hash_equals($this->refreshPaths->basePath(), $parameters['refresh_base_path'])
            || ! hash_equals($parameters['relative_path'], $this->refreshPaths->canonicalizeCandidate($parameters['relative_path']))
            || ! hash_equals($operation->operation_parameters_jcs, $this->canonicalJson->normalizeAndEncode($parameters))) {
            throw new ControlOperationTerminalConflict(
                'refresh_parameters_not_canonical',
                'Die Refresh-Parameter sind nicht kanonisch an die Serverkonfiguration gebunden.',
            );
        }

        return $parameters;
    }

    /** @param array{refresh_base_path: string, relative_path: string} $parameters */
    private function assertExecutionContract(ControlOperation $operation, Project $project, array $parameters): void
    {
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->project_identifier === null
            || $project->pending_control_oid !== null
            || $project->control_oid === null
            || $operation->expected_control_commit === null
            || ! hash_equals($project->control_oid, $operation->expected_control_commit)
            || ! hash_equals($parameters['refresh_base_path'], $this->refreshPaths->basePath())) {
            throw new ControlOperationTerminalConflict(
                'refresh_control_binding_changed',
                'Die aktive Control-Bindung des Refresh-Auftrags ist nicht mehr aktuell.',
            );
        }
    }

    private function assertType(ControlOperation $operation): void
    {
        if ($operation->operation_type !== ControlOperationType::TICKET_REFRESH) {
            throw new RuntimeException('The operation is not a ticket-refresh operation.');
        }
    }
}
