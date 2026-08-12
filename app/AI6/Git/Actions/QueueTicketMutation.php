<?php

namespace App\AI6\Git\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationAuthorizationSnapshot;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationHasher;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketContentStatus;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketStatusOperation;
use App\AI6\Tickets\TicketStatusTransitionPolicy;
use App\AI6\Tickets\TicketV1Parser;
use App\AI6\Tickets\TicketValidationConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueTicketMutation
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
        private ProjectPolicy $projectPolicy,
        private TicketReadModelUsePolicy $usePolicy,
        private TicketReadModelFreshness $freshness,
        private TicketReadModelProjector $projector,
        private TicketValidationConfiguration $validation,
        private TicketV1Parser $parser,
        private TicketContentStatus $statuses,
        private TicketStatusTransitionPolicy $transitions,
        private Redactor $redactor,
    ) {}

    public function edit(
        User $actor,
        Project $project,
        TicketReadModel $readModel,
        string $operationId,
        string $expectedControlOid,
        string $expectedBlob,
        string $baseContent,
        string $targetContent,
        string $reason,
        bool $freshStepUp,
    ): ControlOperation {
        return $this->queue(
            $actor,
            $project,
            $readModel,
            $operationId,
            $expectedControlOid,
            $expectedBlob,
            $baseContent,
            $targetContent,
            $reason,
            $freshStepUp,
            null,
            false,
        );
    }

    public function changeStatus(
        User $actor,
        Project $project,
        TicketReadModel $readModel,
        string $operationId,
        string $expectedControlOid,
        string $expectedBlob,
        string $baseContent,
        string $reason,
        bool $freshStepUp,
        TicketStatusOperation $statusOperation,
        bool $externalCompletionConfirmed,
    ): ControlOperation {
        return $this->queue(
            $actor,
            $project,
            $readModel,
            $operationId,
            $expectedControlOid,
            $expectedBlob,
            $baseContent,
            $baseContent,
            $reason,
            $freshStepUp,
            $statusOperation,
            $externalCompletionConfirmed,
        );
    }

    private function queue(
        User $actor,
        Project $project,
        TicketReadModel $readModel,
        string $operationId,
        string $expectedControlOid,
        string $expectedBlob,
        string $baseContent,
        string $targetContent,
        string $reason,
        bool $freshStepUp,
        ?TicketStatusOperation $statusOperation,
        bool $externalCompletionConfirmed,
    ): ControlOperation {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new TicketMutationConflict('operation_id_invalid', 'Die Mutations-ID ist ungültig.');
        }
        if ($readModel->project_id !== $project->getKey()) {
            throw new TicketMutationConflict('ticket_project_mismatch', 'Die Ticketprojektion gehört nicht zum Projekt.');
        }
        if ($statusOperation === null && ! $this->projectPolicy->editTicket($actor, $project)) {
            throw new AuthorizationException;
        }
        if ($statusOperation !== null && ! $this->projectPolicy->changeTicketStatus($actor, $project)) {
            throw new AuthorizationException;
        }
        $freshness = $this->freshness->for($project, $readModel);
        if (! $this->usePolicy->allowsEditor($readModel, ! $freshness['stale'])) {
            throw new TicketMutationConflict('editor_unavailable', 'Diese Ticketprojektion darf nicht als Editorquelle verwendet werden.');
        }
        if ($readModel->redaction_state !== TicketReadModelRedactionState::CLEAR
            || ! hash_equals($readModel->blob_sha, $expectedBlob)
            || ! hash_equals($readModel->control_commit, $expectedControlOid)
            || ! hash_equals($readModel->redacted_content, $baseContent)) {
            throw new TicketMutationConflict('editor_base_binding_changed', 'Die bytegenaue Editorbasis ist veraltet oder maskiert.');
        }
        if (! $freshStepUp) {
            throw new TicketMutationConflict('step_up_required', 'Die Ticketmutation verlangt eine frische Step-up-Bestätigung.');
        }
        foreach (RedactionMatchType::cases() as $type) {
            if (str_contains($targetContent, $type->marker())) {
                throw new TicketMutationConflict('redaction_marker_detected', 'Ein Redactionmarker darf nicht in den Ticket-Schreibstand gelangen.');
            }
        }

        return DB::transaction(function () use (
            $actor, $project, $readModel, $operationId, $expectedControlOid, $expectedBlob,
            $baseContent, $targetContent, $reason, $statusOperation, $externalCompletionConfirmed,
        ): ControlOperation {
            $currentProject = Project::query()->findOrFail($project->getKey());
            $currentReadModel = TicketReadModel::query()->findOrFail($readModel->getKey());
            if ($currentProject->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
                || $currentProject->control_oid === null
                || ! hash_equals($expectedControlOid, $currentProject->control_oid)
                || $currentProject->pending_control_oid !== null
                || $currentReadModel->project_id !== $currentProject->getKey()
                || ! hash_equals($expectedControlOid, $currentReadModel->control_commit)
                || ! hash_equals($expectedBlob, $currentReadModel->blob_sha)
                || ! hash_equals($baseContent, $currentReadModel->redacted_content)
                || $currentReadModel->redaction_state !== TicketReadModelRedactionState::CLEAR
                || ! $this->usePolicy->allowsEditor(
                    $currentReadModel,
                    ! $this->freshness->for($currentProject, $currentReadModel)['stale'],
                )) {
                throw new TicketMutationConflict('mutation_binding_changed', 'Die Ticket- oder Control-Bindung hat sich geändert.');
            }
            $membership = ProjectMembership::query()
                ->where('project_id', $currentProject->getKey())
                ->where('user_id', $actor->getKey())
                ->first();
            if (! $membership instanceof ProjectMembership) {
                throw new AuthorizationException;
            }

            $sourceStatus = $this->sourceStatus($currentReadModel, $baseContent);
            if ($statusOperation === null) {
                if (! in_array($sourceStatus, ['todo', 'ready', 'blocked'], true)) {
                    throw new TicketMutationConflict('edit_status_not_editable', 'Dieser Ticketstatus darf nicht inhaltlich bearbeitet werden.');
                }
                try {
                    $targetDocument = $this->parser->parse($targetContent);
                } catch (TicketParseException) {
                    throw new TicketMutationConflict('target_validation_failed', 'Der neue Ticketstand ist nicht vollständig gültig.');
                }
                $submittedStatus = $targetDocument->frontmatter['status'] ?? null;
                if ($sourceStatus === 'ready') {
                    if (! in_array($submittedStatus, ['ready', 'todo'], true)) {
                        throw new TicketMutationConflict('edit_status_not_coupled', 'Ein Edit eines Ready-Tickets darf nur atomar nach Todo zurücksetzen.');
                    }
                    if ($submittedStatus === 'ready') {
                        $targetContent = $this->statuses->replace($targetContent, 'ready', 'todo');
                    }
                    $targetStatus = 'todo';
                } else {
                    if ($submittedStatus !== $sourceStatus) {
                        throw new TicketMutationConflict('edit_status_changed', 'Der Editor darf keinen freien Zielstatus übergeben.');
                    }
                    $targetStatus = $sourceStatus;
                }
                $type = ControlOperationType::TICKET_EDIT;
            } else {
                $targetStatus = $this->transitions->decide(
                    $membership->role,
                    $statusOperation,
                    $sourceStatus,
                    true,
                    $expectedBlob,
                    $currentReadModel->blob_sha,
                    $expectedControlOid,
                    (string) $currentProject->control_oid,
                    $externalCompletionConfirmed,
                );
                $targetContent = $this->statuses->replace($baseContent, $sourceStatus, $targetStatus);
                $type = ControlOperationType::TICKET_STATUS_CHANGE;
            }

            $projection = $this->projector->project(
                $targetContent,
                $currentReadModel->relative_path,
                $this->validation->profile,
            );
            if ($projection->state !== TicketDocumentState::VALID || $projection->contractHash === null) {
                throw new TicketMutationConflict('target_validation_failed', 'Der neue Ticketstand ist nicht vollständig gültig.');
            }
            $audit = $this->redactor->redact(
                $reason,
                new RedactionContext((string) $currentProject->getKey(), $operationId, 'ticket-mutation-audit'),
            );
            if (trim($audit->text) === '') {
                throw new TicketMutationConflict('audit_reason_required', 'Ein redigierbarer Auditgrund ist erforderlich.');
            }
            $auditReason = preg_replace('/\s+/u', ' ', trim($audit->text));
            if (! is_string($auditReason) || $auditReason === '') {
                throw new TicketMutationConflict('audit_reason_invalid', 'Der redigierte Auditgrund ist ungültig.');
            }
            $parameters = $type->parameters(array_filter([
                'relative_path' => $currentReadModel->relative_path,
                'expected_binding_version' => $currentProject->control_binding_version,
                'status_operation' => $statusOperation?->value,
            ], static fn (mixed $value): bool => $value !== null));
            $snapshot = $this->authorizationSnapshots->capture($actor, $currentProject);
            $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
            $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
            $coreHash = $this->hasher->hash(
                1,
                (string) $currentProject->project_identifier,
                $type,
                (string) $actor->getKey(),
                $snapshotJcs,
                $expectedControlOid,
                $parameters,
            );
            $targetBlob = hash('sha256', 'blob '.strlen($targetContent)."\0".$targetContent);
            $requestHash = hash('sha256', "AI6-TICKET-MUTATION-REQUEST-V1\0".$coreHash.$expectedBlob.$targetBlob.$projection->contractHash);

            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }
            $attemptToken = $this->lease->claimInitialControlOperation($currentProject, $operationId);
            if ($attemptToken === null) {
                throw new TicketMutationConflict('project_operation_locked', 'Eine andere mutierende Projektoperation ist aktiv.');
            }
            $operation = ControlOperation::query()->create([
                'id' => strtolower($operationId),
                'project_id' => $currentProject->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => $type,
                'schema_version' => 1,
                'authorization_snapshot' => $snapshot,
                'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $expectedControlOid,
                'operation_parameters_jcs' => $parametersJcs,
                'request_hash' => $requestHash,
                'phase' => ControlOperationPhase::PREPARED,
                'state' => ControlOperationState::QUEUED,
                'current_attempt_token' => $attemptToken,
            ]);
            TicketMutation::query()->create([
                'status_operation_id' => $operation->id,
                'relative_path' => $currentReadModel->relative_path,
                'expected_ticket_blob_sha' => $expectedBlob,
                'base_content_sha256' => hash('sha256', $baseContent),
                'base_content' => $baseContent,
                'target_content' => $targetContent,
                'source_status' => $sourceStatus,
                'target_status' => $targetStatus,
                'source_contract_sha256' => $currentReadModel->ticket_contract_sha256,
                'target_contract_sha256' => $projection->contractHash,
                'expected_target_blob_sha' => $targetBlob,
                'expected_target_tree_oid' => str_repeat('0', 64),
                'expected_control_binding_version' => $currentProject->control_binding_version,
                'audit_reason' => $auditReason,
                'audit_redaction_matches' => array_map(
                    static fn ($match): array => $match->jsonSerialize(),
                    $audit->matches,
                ),
                'external_completion_confirmed' => $externalCompletionConfirmed,
                'commit_timestamp' => now()->getTimestamp(),
            ]);
            Queue::connection('database')->push(new ExecuteControlOperation($operation->id));

            return $operation;
        });
    }

    private function sourceStatus(TicketReadModel $readModel, string $baseContent): string
    {
        try {
            $status = $this->parser->parse($baseContent)->frontmatter['status'] ?? null;
        } catch (\Throwable) {
            $status = null;
        }
        if (is_string($status) && in_array($status, ['todo', 'ready', 'blocked', 'review'], true)) {
            return $status;
        }
        if (is_string($status)) {
            throw new TicketMutationConflict('source_status_unavailable', 'Der gebundene Quellstatus ist nicht mutierbar.');
        }
        if ($readModel->document_state === TicketDocumentState::INVALID) {
            return 'todo';
        }

        throw new TicketMutationConflict('source_status_unavailable', 'Der gebundene Quellstatus ist nicht mutierbar.');
    }
}
