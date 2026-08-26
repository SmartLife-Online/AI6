<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketMutationConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The shared status-operation-bound soft/block/hard cancellation saga. */
final readonly class RunCancellationService
{
    public function __construct(
        private ProjectPolicy $policy,
        private QueueTicketMutation $ticketMutations,
        private RunOrchestrator $orchestrator,
        private Redactor $redactor,
        private HumanRequestService $humanRequests,
    ) {}

    public function request(
        HumanRequest $request,
        User $actor,
        int $expectedRunVersion,
        RunCancellationMode $mode,
        string $reason,
        ?InterventionAuthorization $authorization,
    ): Intervention {
        $idempotencyKey = hash('sha256', implode(':', [
            $request->id, $actor->getKey(), $expectedRunVersion, $mode->value,
        ]));
        $existing = Intervention::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof Intervention) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($request, $actor, $expectedRunVersion, $mode, $reason, $authorization, $idempotencyKey): Intervention {
                DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
                $freshRequest = HumanRequest::query()->findOrFail($request->id);
                $run = Run::query()->findOrFail($freshRequest->run_id);
                $project = Project::query()->findOrFail($run->project_id);
                if (! $this->policy->decide(ProjectAction::INTERVENE_RUN, $actor, $project)) {
                    throw new HumanRequestRejected('unauthorized', 'The actor may not intervene in this run.');
                }
                $membership = ProjectMembership::query()->where('project_id', $project->id)
                    ->where('user_id', $actor->getKey())->first();
                if (! $membership instanceof ProjectMembership
                    || ($mode->requiresApprover() && $membership->role !== ProjectRole::APPROVER)) {
                    throw new HumanRequestRejected('strong_authorization_required', 'This cancellation requires the approver role.');
                }
                if (! $authorization instanceof InterventionAuthorization) {
                    throw new HumanRequestRejected('step_up_required', 'This cancellation requires a fresh step-up proof.');
                }
                if ($freshRequest->resolution_state !== HumanRequestResolutionState::OPEN) {
                    throw new HumanRequestRejected('request_already_resolved', 'The intervention request is already resolved.');
                }
                if ($run->confirmed_branch_publication_oid !== null) {
                    throw new HumanRequestRejected('cancel_after_push_forbidden', 'A confirmed branch publication can only continue through status synchronization.');
                }
                if ($run->version !== $expectedRunVersion || $freshRequest->bound_run_version !== $expectedRunVersion) {
                    throw new HumanRequestRejected('stale_run_version', 'The run version changed before the intervention.');
                }
                if ($run->state !== RunState::WAITING || ! $run->wait_reason instanceof WaitReason) {
                    throw new HumanRequestRejected('run_not_waiting', 'Only a bound waiting run can be cancelled from the intervention panel.');
                }

                $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
                $readModel = TicketReadModel::query()->where('project_id', $project->id)
                    ->where('relative_path', $approval->relative_path)->firstOrFail();
                if ($project->control_oid === '' || $readModel->redacted_content === '') {
                    throw new HumanRequestRejected('cancel_binding_missing', 'The ticket status binding is unavailable.');
                }
                $redactedReason = trim($this->redactor->redact(
                    $reason,
                    new RedactionContext((string) $project->id, $run->id, 'run-cancellation'),
                )->text);
                if ($redactedReason === '') {
                    throw new HumanRequestRejected('reason_required', 'The cancellation reason is required.');
                }

                $operation = $this->ticketMutations->changeRunStatus(
                    $actor,
                    $project,
                    $readModel,
                    (string) Str::uuid(),
                    $project->control_oid,
                    $readModel->blob_sha,
                    $readModel->redacted_content,
                    $redactedReason,
                    true,
                    $mode->statusOperation(),
                );

                $intervention = Intervention::query()->create([
                    'id' => (string) Str::uuid(),
                    'human_request_id' => $freshRequest->id,
                    'user_id' => $actor->getKey(),
                    'actor_role' => $membership->role->value,
                    'step_up_verified' => true,
                    'step_up_proof_hash' => $authorization->proofHash,
                    'chosen_effect' => $mode->value,
                    'chosen_option_key' => $mode->value,
                    'expected_run_version' => $expectedRunVersion,
                    'wait_reason' => $run->wait_reason->value,
                    'bound_step_key' => $freshRequest->bound_step_key,
                    'reason' => $redactedReason,
                    'idempotency_key' => $idempotencyKey,
                    'status_operation_id' => $operation->id,
                ]);
                $freshRequest->forceFill([
                    'resolution_state' => HumanRequestResolutionState::CANCELLED,
                    'resolved_at' => now(),
                ])->save();
                $this->orchestrator->bindCancellationOperation($run, $expectedRunVersion, $operation);

                return $intervention;
            });
        } catch (TicketMutationConflict $conflict) {
            throw new HumanRequestRejected($conflict->conflict, $conflict->getMessage());
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected($conflict->reason, $conflict->getMessage());
        }
    }

    public function reconcileOperation(ControlOperation $operation): ?Run
    {
        if ($this->isReportOnlyCompletion($operation)) {
            return null;
        }
        $run = Run::query()->where('pending_status_operation_id', $operation->id)->first();
        if (! $run instanceof Run) {
            return null;
        }
        if ($run->state === RunState::CANCELLED) {
            return $run;
        }
        if ($operation->state !== ControlOperationState::COMPLETED) {
            return null;
        }

        return $this->orchestrator->transition(
            $run,
            $run->version,
            RunState::CANCELLED,
            $run->phase,
            confirmedStatusOperation: $operation,
        );
    }

    public function recordConflict(ControlOperation $operation): ?Run
    {
        if ($this->isReportOnlyCompletion($operation)) {
            return null;
        }
        $run = Run::query()->where('pending_status_operation_id', $operation->id)->first();
        if ($run instanceof Run) {
            $parked = $this->orchestrator->parkOnGitConflict($run, $run->version);
            // The conflicted binding is superseded so the refreshed, re-authorized
            // decision can bind a fresh status operation (AC-14). The conflicted
            // operation and its intervention stay readable as evidence.
            $parked = $this->orchestrator->releaseCancellationOperation($parked, $operation);
        } else {
            // Idempotent redelivery after the binding was already released: the
            // run is only resolvable through the recorded intervention, and only
            // an unchanged git_conflict park is acknowledged — a run that has
            // since bound a new operation or left the wait is left untouched.
            $intervention = Intervention::query()->where('status_operation_id', $operation->id)->first();
            $priorRequest = $intervention instanceof Intervention
                ? HumanRequest::query()->find($intervention->human_request_id)
                : null;
            $parked = $priorRequest instanceof HumanRequest
                ? Run::query()->find($priorRequest->run_id)
                : null;
            if (! $parked instanceof Run
                || $parked->state !== RunState::WAITING
                || $parked->wait_reason !== WaitReason::GIT_CONFLICT
                || $parked->pending_status_operation_id !== null) {
                return null;
            }
        }
        if (HumanRequest::query()->where('run_id', $parked->id)
            ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->exists()) {
            return $parked;
        }
        $intervention = Intervention::query()->where('status_operation_id', $operation->id)->first();
        $priorRequest = $intervention instanceof Intervention
            ? HumanRequest::query()->find($intervention->human_request_id)
            : null;
        if ($priorRequest instanceof HumanRequest) {
            $this->humanRequests->openGitConflictRequest(
                $parked,
                $priorRequest->bound_agent_slot,
                $priorRequest->bound_step_key,
            );
        }

        return $parked;
    }

    private function isReportOnlyCompletion(ControlOperation $operation): bool
    {
        try {
            $parameters = json_decode($operation->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($parameters)
            && ($parameters['status_operation'] ?? null) === 'complete_report_only';
    }
}
