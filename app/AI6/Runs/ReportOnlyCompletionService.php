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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The exclusive entry and reconciliation seam of the report-only completion saga. */
final readonly class ReportOnlyCompletionService
{
    public function __construct(
        private ReviewOnlyCompletionPredicate $predicate,
        private QueueTicketMutation $ticketMutations,
        private RunOrchestrator $orchestrator,
        private HumanRequestService $humanRequests,
        private ProjectPolicy $policy,
    ) {}

    public function start(Run $run): Run|HumanRequest
    {
        $run->refresh();
        $this->assertReady($run);
        if ($run->completion_mode === ReviewOnlyCompletionMode::MANUAL) {
            return $this->humanRequests->openManualReportRequest($run);
        }
        if ($run->completion_mode !== ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES) {
            throw new RunTransitionConflict('completion_mode_invalid', 'The review-only completion mode is not bound.');
        }
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $actor = User::query()->findOrFail($approval->approved_by);

        return $this->queueCompletion($run, $actor);
    }

    public function confirm(HumanRequest $request, User $actor, ?InterventionAuthorization $authorization): Run
    {
        return DB::transaction(function () use ($request, $actor, $authorization): Run {
            DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
            $request = HumanRequest::query()->findOrFail($request->id);
            $run = Run::query()->findOrFail($request->run_id);
            $project = Project::query()->findOrFail($run->project_id);
            $membership = ProjectMembership::query()->where('project_id', $project->id)
                ->where('user_id', $actor->getKey())->first();
            if (! $authorization instanceof InterventionAuthorization
                || ! $this->policy->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $actor, $project)
                || ! $membership instanceof ProjectMembership || $membership->role !== ProjectRole::APPROVER) {
                throw new HumanRequestRejected('unauthorized', 'Only an authorized approver may confirm the report-only completion.');
            }
            if ($request->resolution_state !== HumanRequestResolutionState::OPEN
                || $request->kind !== WaitReason::MANUAL_REPORT->value
                || $run->state !== RunState::WAITING || $run->wait_reason !== WaitReason::MANUAL_REPORT
                || $run->version !== $request->bound_run_version
                || $run->checkpoint_tree_sha === null || ! hash_equals($run->checkpoint_tree_sha, $request->bound_checkpoint)
                || ! hash_equals($this->reviewBinding($run), $request->bound_requested_effect)) {
                throw new HumanRequestRejected('stale_report_confirmation', 'The bound review state changed before confirmation.');
            }
            $this->currentControlReadModel($run);
            $decision = $this->predicate->decide($run, $request->id);
            if (! $decision->ready()) {
                throw new HumanRequestRejected('report_completion_blocked', 'The report-only completion predicate is not satisfied.');
            }
            $completed = $this->queueCompletion($run, $actor);
            Intervention::query()->create([
                'id' => (string) Str::uuid(),
                'human_request_id' => $request->id,
                'user_id' => $actor->getKey(),
                'actor_role' => $membership->role->value,
                'step_up_verified' => true,
                'step_up_proof_hash' => $authorization->proofHash,
                'chosen_effect' => 'confirm_report',
                'chosen_option_key' => 'confirm_report',
                'expected_run_version' => $request->bound_run_version,
                'wait_reason' => WaitReason::MANUAL_REPORT->value,
                'bound_step_key' => $request->bound_step_key,
                'reason' => 'Gebundener report-only Abschluss bestätigt.',
                'idempotency_key' => hash('sha256', $request->id.':confirm_report'),
                'status_operation_id' => $completed->pending_status_operation_id,
            ]);
            $request->forceFill([
                'resolution_state' => HumanRequestResolutionState::ANSWERED,
                'resolved_at' => now(),
            ])->save();

            return $completed;
        });
    }

    public function reconcileOperation(ControlOperation $operation): ?Run
    {
        $run = Run::query()->where('pending_status_operation_id', $operation->id)
            ->where('run_type', RunType::REVIEW_ONLY->value)->first();
        if (! $run instanceof Run || $run->state === RunState::COMPLETED) {
            return $run;
        }
        if ($operation->state !== ControlOperationState::COMPLETED) {
            return null;
        }

        return $this->orchestrator->transition(
            $run,
            $run->version,
            RunState::COMPLETED,
            RunPhase::FINALIZE,
            confirmedStatusOperation: $operation,
        );
    }

    public function recordConflict(ControlOperation $operation): ?Run
    {
        $run = Run::query()->where('pending_status_operation_id', $operation->id)
            ->where('run_type', RunType::REVIEW_ONLY->value)->first();
        if (! $run instanceof Run) {
            return null;
        }
        $run = $this->orchestrator->parkOnGitConflict($run, $run->version);
        $run = $this->orchestrator->releaseCancellationOperation($run, $operation);
        if (! HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->exists()) {
            $this->humanRequests->openReportStatusConflictRequest($run);
        }

        return $run;
    }

    public function resolveStatusConflict(HumanRequest $request, User $actor, ?InterventionAuthorization $authorization): Run
    {
        return DB::transaction(function () use ($request, $actor, $authorization): Run {
            DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
            $request = HumanRequest::query()->findOrFail($request->id);
            $run = Run::query()->findOrFail($request->run_id);
            if (! $authorization instanceof InterventionAuthorization) {
                throw new HumanRequestRejected('step_up_required', 'The report status conflict decision requires a fresh step-up proof.');
            }
            $membership = $this->authorizedApprover($run, $actor);
            if ($request->resolution_state !== HumanRequestResolutionState::OPEN
                || $request->kind !== WaitReason::GIT_CONFLICT->value
                || $run->state !== RunState::WAITING || $run->wait_reason !== WaitReason::GIT_CONFLICT
                || $run->pending_status_operation_id !== null
                || $run->version !== $request->bound_run_version
                || $run->checkpoint_tree_sha === null || ! hash_equals($run->checkpoint_tree_sha, $request->bound_checkpoint)
                || ! hash_equals($this->reviewBinding($run), $request->bound_requested_effect)) {
                throw new HumanRequestRejected('stale_status_conflict_decision', 'The bound status conflict state changed before authorization.');
            }
            $decision = $this->predicate->decide($run, $request->id);
            if (! $decision->ready()) {
                throw new HumanRequestRejected('report_completion_blocked', 'The report-only completion predicate is not satisfied.');
            }
            $syncing = $this->queueCompletion($run, $actor);
            Intervention::query()->create([
                'id' => (string) Str::uuid(),
                'human_request_id' => $request->id,
                'user_id' => $actor->getKey(),
                'actor_role' => $membership->role->value,
                'step_up_verified' => true,
                'step_up_proof_hash' => $authorization->proofHash,
                'chosen_effect' => 'refresh_expected_oid',
                'chosen_option_key' => 'refresh_expected_oid',
                'expected_run_version' => $request->bound_run_version,
                'wait_reason' => WaitReason::GIT_CONFLICT->value,
                'bound_step_key' => $request->bound_step_key,
                'reason' => 'Report-only Statusabgleich gegen die aktuelle Control-OID erneut autorisiert.',
                'idempotency_key' => hash('sha256', $request->id.':refresh_expected_oid'),
                'status_operation_id' => $syncing->pending_status_operation_id,
            ]);
            $request->forceFill([
                'resolution_state' => HumanRequestResolutionState::ANSWERED,
                'resolved_at' => now(),
            ])->save();

            return $syncing;
        });
    }

    private function queueCompletion(Run $run, User $actor): Run
    {
        return DB::transaction(function () use ($run, $actor): Run {
            DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
            $run = Run::query()->findOrFail($run->id);
            $project = Project::query()->findOrFail($run->project_id);
            $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
            $readModel = $this->currentControlReadModel($run);
            $operation = $this->ticketMutations->completeReviewOnlyRun(
                $actor,
                $project,
                $readModel,
                $run,
                (string) Str::uuid(),
                'Gebundener report-only Abschluss.',
            );

            return $this->orchestrator->bindReportOnlyCompletionOperation($run, $run->version, $operation);
        });
    }

    private function assertReady(Run $run): void
    {
        $decision = $this->predicate->decide($run);
        if (! $decision->ready()) {
            throw new RunTransitionConflict('report_completion_blocked', implode(',', $decision->blockers));
        }
    }

    private function currentControlReadModel(Run $run): TicketReadModel
    {
        $project = Project::query()->findOrFail($run->project_id);
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $readModel = TicketReadModel::query()->where('project_id', $project->id)
            ->where('relative_path', $approval->relative_path)
            ->where('control_commit', $project->control_oid)->latest('generated_at')->first();
        if (! $readModel instanceof TicketReadModel) {
            throw new HumanRequestRejected(
                'report_status_binding_missing',
                'The current control commit has no ticket read model for the report-only status synchronization.',
            );
        }

        return $readModel;
    }

    private function authorizedApprover(Run $run, User $actor): ProjectMembership
    {
        $project = Project::query()->findOrFail($run->project_id);
        $membership = ProjectMembership::query()->where('project_id', $project->id)
            ->where('user_id', $actor->getKey())->first();
        if (! $this->policy->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $actor, $project)
            || ! $membership instanceof ProjectMembership || $membership->role !== ProjectRole::APPROVER) {
            throw new HumanRequestRejected('unauthorized', 'Only an authorized approver may decide the report status conflict.');
        }

        return $membership;
    }

    private function reviewBinding(Run $run): string
    {
        return hash('sha256', implode(':', [
            $run->checkpoint_tree_sha,
            $run->checkpoint_diff_hash,
            $run->agent_profile_hash,
            $run->evidence_epoch,
        ]));
    }
}
