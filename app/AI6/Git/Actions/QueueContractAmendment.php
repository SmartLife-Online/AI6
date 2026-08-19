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
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Runs\InstructionPathPolicy;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Queue the contract-amendment saga for the active run (plan §8.2).
 *
 * The amendment writes an authorized ticket patch with the run's currently
 * persisted run_base_sha as the expected control OID; the worker publishes it
 * per compare-and-swap onto the control branch, keeps the ticket status
 * in_progress, and registers the resulting commit exclusively as the run's new
 * run_base_sha. initial_run_base_sha and approved_control_sha never move.
 */
final readonly class QueueContractAmendment
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
        private EffectiveProjectConfiguration $projectConfiguration,
        private TicketV1Parser $parser,
        private InstructionPathPolicy $instructionPaths,
        private Redactor $redactor,
    ) {}

    public function handle(
        User $actor,
        Project $project,
        Run $run,
        TicketReadModel $readModel,
        string $operationId,
        string $targetContent,
        string $reason,
        bool $freshStepUp,
        ?string $humanRequestId = null,
    ): ControlOperation {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new TicketMutationConflict('operation_id_invalid', 'Die Mutations-ID ist ungültig.');
        }
        if ($readModel->project_id !== $project->getKey() || $run->project_id !== $project->getKey()) {
            throw new TicketMutationConflict('ticket_project_mismatch', 'Die Ticketprojektion gehört nicht zum Projekt.');
        }
        if (! $this->projectPolicy->editTicket($actor, $project)) {
            throw new AuthorizationException;
        }
        if (! $freshStepUp) {
            throw new TicketMutationConflict('step_up_required', 'Die Vertragsänderung verlangt eine frische Step-up-Bestätigung.');
        }
        foreach (RedactionMatchType::cases() as $type) {
            if (str_contains($targetContent, $type->marker())) {
                throw new TicketMutationConflict('redaction_marker_detected', 'Ein Redactionmarker darf nicht in den Ticket-Schreibstand gelangen.');
            }
        }

        return DB::transaction(function () use ($actor, $project, $run, $readModel, $operationId, $targetContent, $reason, $humanRequestId): ControlOperation {
            $currentProject = Project::query()->findOrFail($project->getKey());
            $currentRun = Run::query()->findOrFail($run->getKey());
            $currentReadModel = TicketReadModel::query()->findOrFail($readModel->getKey());
            $binding = $this->projectConfiguration->for($currentProject);
            $approvalPath = TicketApproval::query()->whereKey($currentRun->ticket_approval_id)->value('relative_path');
            if ($currentProject->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
                || $currentProject->pending_control_oid !== null
                || $currentProject->active_run_id !== $currentRun->getKey()
                || in_array($currentRun->state, [RunState::COMPLETED, RunState::CANCELLED], true)
                || ! is_string($approvalPath)
                || ! hash_equals($approvalPath, $currentReadModel->relative_path)
                || ! hash_equals($currentRun->run_base_sha, (string) $currentProject->control_oid)
                || ! hash_equals($currentRun->run_base_sha, $currentReadModel->control_commit)
                || $currentReadModel->redaction_state !== TicketReadModelRedactionState::CLEAR
                || ! $this->usePolicy->allowsAmendment(
                    $currentReadModel,
                    ! $this->freshness->for($currentProject, $currentReadModel)['stale'],
                    $binding->configuration->ticketValidationProfile(),
                )) {
                throw new TicketMutationConflict('amendment_binding_changed', 'Die Run- oder Ticketbindung der Vertragsänderung ist nicht mehr aktuell.');
            }

            $baseContent = $currentReadModel->redacted_content;
            try {
                $baseDocument = $this->parser->parse($baseContent);
                $targetDocument = $this->parser->parse($targetContent);
            } catch (TicketParseException) {
                throw new TicketMutationConflict('target_validation_failed', 'Der neue Ticketstand ist nicht vollständig gültig.');
            }
            if (($baseDocument->frontmatter['status'] ?? null) !== 'in_progress'
                || ($targetDocument->frontmatter['status'] ?? null) !== 'in_progress') {
                throw new TicketMutationConflict('amendment_status_not_in_progress', 'Eine Vertragsänderung behält den Ticketstatus in_progress bei.');
            }
            foreach (array_diff($targetDocument->files, $baseDocument->files) as $added) {
                if ($this->instructionPaths->isInstructionPath($added)) {
                    throw new TicketMutationConflict('instruction_scope_extension_forbidden', 'Ein Instruktionspfad darf nicht per Same-run-Amendment in den Scope gelangen.');
                }
            }

            $projection = $this->projector->project(
                $targetContent,
                $currentReadModel->relative_path,
                $binding->configuration->ticketValidationProfile(),
            );
            if ($projection->state !== TicketDocumentState::VALID || $projection->contractHash === null) {
                throw new TicketMutationConflict('target_validation_failed', 'Der neue Ticketstand ist nicht vollständig gültig.');
            }

            $audit = $this->redactor->redact(
                $reason,
                new RedactionContext((string) $currentProject->getKey(), $operationId, 'contract-amendment-audit'),
            );
            $auditReason = preg_replace('/\s+/u', ' ', trim($audit->text));
            if (! is_string($auditReason) || $auditReason === '') {
                throw new TicketMutationConflict('audit_reason_required', 'Ein redigierbarer Auditgrund ist erforderlich.');
            }

            $expectedControlOid = $currentRun->run_base_sha;
            $expectedBlob = $currentReadModel->blob_sha;
            $parameters = ControlOperationType::CONTRACT_AMENDMENT->parameters([
                'run_id' => $currentRun->getKey(),
                'relative_path' => $currentReadModel->relative_path,
                'expected_binding_version' => $currentProject->control_binding_version,
                'human_request_id' => $humanRequestId,
            ]);
            $snapshot = $this->authorizationSnapshots->capture($actor, $currentProject);
            $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
            $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
            $coreHash = $this->hasher->hash(
                1,
                (string) $currentProject->project_identifier,
                ControlOperationType::CONTRACT_AMENDMENT,
                (string) $actor->getKey(),
                $snapshotJcs,
                $expectedControlOid,
                $parameters,
            );
            $targetBlob = hash('sha256', 'blob '.strlen($targetContent)."\0".$targetContent);
            $requestHash = hash('sha256', "AI6-CONTRACT-AMENDMENT-REQUEST-V1\0".$coreHash.$expectedBlob.$targetBlob.$projection->contractHash);

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
                'operation_type' => ControlOperationType::CONTRACT_AMENDMENT,
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
                'source_status' => 'in_progress',
                'target_status' => 'in_progress',
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
                'external_completion_confirmed' => false,
                'commit_timestamp' => now()->getTimestamp(),
            ]);
            Queue::connection('database')->push(new ExecuteControlOperation($operation->id));

            return $operation;
        });
    }
}
