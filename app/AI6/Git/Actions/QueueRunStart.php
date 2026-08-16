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
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Runs\ApprovalSnapshotVerifier;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Tickets\TicketContentStatus;
use App\AI6\Tickets\TicketReadModelProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueRunStart
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
        private ProjectPolicy $policy,
        private EffectiveProjectConfiguration $configurations,
        private ApprovalSnapshotVerifier $snapshots,
        private TicketContentStatus $statuses,
        private TicketReadModelProjector $projector,
    ) {}

    public function handle(User $actor, Project $project, string $approvalId, string $operationId): ControlOperation
    {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->policy->startRun($actor, $project)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $approvalId, $operationId): ControlOperation {
            $project = Project::query()->findOrFail($project->getKey());
            $approval = TicketApproval::query()
                ->whereKey($approvalId)
                ->where('project_id', $project->getKey())
                ->firstOrFail();
            if (! $this->policy->startRun($actor, $project)
                || $approval->queue_state !== 'queued'
                || $approval->saga_phase !== 'complete'
                || $approval->approved_ticket_blob_sha === null
                || $approval->approved_control_sha === null
                || $project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
                || $project->project_identifier === null
                || $project->control_oid === null
                || $project->control_generation !== $approval->control_generation) {
                throw new ControlOperationConflict('The approval is not startable.');
            }
            $readModel = TicketReadModel::query()
                ->where('project_id', $project->getKey())
                ->where('relative_path', $approval->relative_path)
                ->where('control_commit', $project->control_oid)
                ->where('control_generation', $approval->control_generation)
                ->latest('generated_at')
                ->first();
            if (! $readModel instanceof TicketReadModel
                || $readModel->document_state !== TicketDocumentState::VALID
                || $readModel->redaction_state !== TicketReadModelRedactionState::CLEAR
                || ! hash_equals($approval->approved_ticket_blob_sha, $readModel->blob_sha)
                || ! hash_equals($approval->ticket_contract_sha256, (string) $readModel->ticket_contract_sha256)
                || ! hash_equals($readModel->blob_sha, hash('sha256', 'blob '.strlen($readModel->redacted_content)."\0".$readModel->redacted_content))
                || ! $this->snapshots->matches($approval, $project, $readModel)) {
                throw new ControlOperationConflict('The approved ready ticket changed before run start.');
            }

            $target = $this->statuses->replace($readModel->redacted_content, 'ready', 'in_progress');
            $binding = $this->configurations->for($project);
            $projection = $this->projector->project($target, $approval->relative_path, $binding->configuration->ticketValidationProfile());
            if ($projection->state !== TicketDocumentState::VALID
                || $projection->contractHash === null
                || ! hash_equals($approval->ticket_contract_sha256, $projection->contractHash)) {
                throw new ControlOperationConflict('The run claim changed the approved ticket contract.');
            }

            $operationId = strtolower($operationId);
            $parameters = ControlOperationType::RUN_START->parameters([
                'approval_id' => $approval->getKey(),
                'relative_path' => $approval->relative_path,
                'expected_binding_version' => $project->control_binding_version,
            ]);
            $snapshot = $this->authorizationSnapshots->capture($actor, $project);
            $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
            $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
            $requestHash = $this->hasher->hash(1, $project->project_identifier, ControlOperationType::RUN_START, (string) $actor->getKey(), $snapshotJcs, $project->control_oid, $parameters);
            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }
            $attemptToken = $this->lease->claimInitialControlOperation($project, $operationId, requiresInactiveRun: true);
            if ($attemptToken === null) {
                throw new ControlOperationConflict('The project cannot be claimed for run start.');
            }
            $operation = ControlOperation::query()->create([
                'id' => $operationId,
                'project_id' => $project->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => ControlOperationType::RUN_START,
                'schema_version' => 1,
                'authorization_snapshot' => $snapshot,
                'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $project->control_oid,
                'operation_parameters_jcs' => $parametersJcs,
                'request_hash' => $requestHash,
                'phase' => ControlOperationPhase::PREPARED,
                'state' => ControlOperationState::QUEUED,
                'current_attempt_token' => $attemptToken,
            ]);
            TicketMutation::query()->create([
                'status_operation_id' => $operation->id,
                'relative_path' => $approval->relative_path,
                'expected_ticket_blob_sha' => $readModel->blob_sha,
                'base_content_sha256' => hash('sha256', $readModel->redacted_content),
                'base_content' => $readModel->redacted_content,
                'target_content' => $target,
                'source_status' => 'ready',
                'target_status' => 'in_progress',
                'source_contract_sha256' => $approval->ticket_contract_sha256,
                'target_contract_sha256' => $projection->contractHash,
                'expected_target_blob_sha' => hash('sha256', 'blob '.strlen($target)."\0".$target),
                'expected_target_tree_oid' => str_repeat('0', 64),
                'expected_control_binding_version' => $project->control_binding_version,
                'audit_reason' => 'Runstart aus freigegebener Approval.',
                'audit_redaction_matches' => [],
                'external_completion_confirmed' => false,
                'commit_timestamp' => now()->getTimestamp(),
            ]);
            Queue::connection('database')->push(new ExecuteControlOperation($operation->id));

            return $operation;
        });
    }
}
