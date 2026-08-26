<?php

namespace Tests\Feature\Runs;

use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestResumeTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-09 */
    public function test_an_accepted_answer_resumes_the_bound_step_once(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-RES');
        $request = $opened['request'];
        $step = ExecutionJob::query()->where('run_id', $opened['run']->id)
            ->where('idempotency_key', $request->bound_step_key)->firstOrFail();
        self::assertSame(ExecutionJobState::WAITING, $step->state);

        $this->actingAs($opened['operator'])->post(
            route('projects.human-requests.answer', [$opened['project'], $request->id]),
            [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => 'a',
            ],
        )->assertRedirect(route('projects.runs.show', [$opened['project'], $opened['run']->id]));

        $run = $opened['run']->fresh();
        self::assertSame(RunState::RUNNING, $run->state);
        self::assertNull($run->wait_reason);
        self::assertSame(ExecutionJobState::PLANNED, $step->fresh()->state);
        self::assertSame(1, DB::table('jobs')->where('payload', 'like', '%ExecuteRunStep%')->count());

        $plannedEvents = RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.implement.planned')->count();

        $this->actingAs($opened['operator'])->post(
            route('projects.human-requests.answer', [$opened['project'], $request->id]),
            [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => 'a',
            ],
        );

        self::assertSame($plannedEvents, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.implement.planned')->count());
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
    }

    /** TC-09 */
    public function test_legacy_cancel_cannot_bypass_the_status_bound_saga(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-CAN');
        $request = $opened['request'];

        try {
            $this->app->make(HumanRequestService::class)->answer(
                $request,
                $opened['operator'],
                $request->bound_run_version,
                $request->bound_ticket_contract,
                $request->bound_checkpoint,
                $request->bound_scope,
                $request->bound_agent_slot,
                $request->bound_requested_effect,
                HumanRequestService::CANCEL_EFFECT,
            );
            self::fail('The legacy cancel path was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('legacy_cancel_forbidden', $rejected->reason);
        }

        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
        self::assertSame('open', $request->fresh()->resolution_state->value);
    }

    /**
     * TC-10; AI6-020 AC-14/TC-12 extend the closed list by scope_approval and
     * contract_change, AI6-021 AC-11 by check_failure.
     */
    public function test_human_question_and_resource_limit_are_registered_with_resolvers(): void
    {
        $registry = $this->app->make(WaitReasonRegistry::class);
        self::assertSame([
            'human_question', 'resource_limit', 'scope_approval', 'contract_change', 'check_failure',
            'review_limit', 'provider_error', 'invalid_json', 'git_base_changed', 'git_conflict',
            'manual_report', 'status_sync',
        ], $registry->registeredReasons());
        self::assertSame([
            'producer' => 'needs_human',
            'resolvers' => ['bound_answer'],
            'cancellable' => true,
        ], $registry->registration(WaitReason::HUMAN_QUESTION));
        self::assertSame([
            'producer' => 'RunLimitPolicy',
            'resolvers' => ['reduce', 'increase'],
            'cancellable' => true,
        ], $registry->registration(WaitReason::RESOURCE_LIMIT));
        self::assertSame([
            'producer' => 'ScopeApprovalService',
            'resolvers' => ['approve', 'reject'],
            'cancellable' => true,
        ], $registry->registration(WaitReason::SCOPE_APPROVAL));
        self::assertSame([
            'producer' => 'ContractChangeService',
            'resolvers' => ['amendment_cas', 'return_to_todo'],
            'cancellable' => true,
        ], $registry->registration(WaitReason::CONTRACT_CHANGE));

        try {
            $registry->register(WaitReason::MANUAL_GATE, 'gate');
            self::fail('An unpaired producer must fail.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('unpaired_wait_reason_producer', $conflict->reason);
        }
        self::assertFalse($registry->isRegistered(WaitReason::MANUAL_GATE));
    }
}
