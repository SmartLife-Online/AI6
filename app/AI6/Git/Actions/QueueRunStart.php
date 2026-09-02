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
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Tickets\TicketContentStatus;
use App\AI6\Tickets\TicketReadModelProjector;
use Illuminate\Auth\Access\AuthorizationException;
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
        private TicketContentStatus $statuses,
        private TicketReadModelProjector $projector,
    ) {}

    public function handleVerified(
        User $actor,
        Project $project,
        TicketApproval $approval,
        TicketReadModel $readModel,
        string $operationId,
        bool $automatic = false,
    ): ControlOperation {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->policy->startRun($actor, $project)) {
            throw new AuthorizationException;
        }

        return $this->persist($actor, $project, $approval, $readModel, $operationId, $automatic);
    }

    private function persist(User $actor, Project $project, TicketApproval $approval, TicketReadModel $readModel, string $operationId, bool $automatic = false): ControlOperation
    {
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED) {
            throw new ControlOperationConflict('The project is not provisioned for run start.');
        }
        if (! is_string($project->project_identifier) || $project->project_identifier === '') {
            throw new ControlOperationConflict('The project identifier is missing for run start.');
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
            'run_type' => $approval->run_type->value,
            'relative_path' => $approval->relative_path,
            'expected_binding_version' => $project->control_binding_version,
        ]);
        $snapshot = $this->authorizationSnapshots->capture(
            $actor,
            $project,
            $automatic ? 'approval_auto_start' : 'global_and_project_administrator',
        );
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
    }
}
