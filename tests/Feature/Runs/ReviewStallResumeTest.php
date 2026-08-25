<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewStallFingerprint;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AC-04/AC-06: the stall event fires once before the next agent call, and its
 * granted resolution actually continues the bound fix step instead of parking
 * the resumed run behind review_limit again.
 */
final class ReviewStallResumeTest extends TicketUiTestCase
{
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    public function test_a_granted_additional_round_continues_the_stalled_fix_step(): void
    {
        $stalled = $this->stalledRun('AI6-026-STALL-GRANT');
        $run = $stalled['run'];
        $request = $stalled['request'];
        $limits = $this->app->make(RunLimitPolicy::class);
        $reviewLimitBefore = $limits->effective($run->fresh())[ImportLimit::MAX_REVIEW_ROUNDS->value];

        $approver = $this->approver($run);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $approver,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'additional_round',
            $this->authorization($approver, $request, 'additional_round'),
        );

        // The grant raises the stalled round limit by exactly one and resumes
        // only the bound fix step.
        self::assertSame(
            $reviewLimitBefore + 1,
            $limits->effective($run->fresh())[ImportLimit::MAX_REVIEW_ROUNDS->value],
        );
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(
            ExecutionJobState::PLANNED,
            $this->stepJob($run, ExecutionStepType::FIX, 2)->state,
        );

        // The resumed step passes the one-shot stall gate and reaches the real
        // fix turn instead of parking behind review_limit again.
        $fix = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $fix->state, (string) $fix->failure_code);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->count());
    }

    public function test_a_reviewer_switch_resolves_the_stall_with_a_new_slot_revision(): void
    {
        $stalled = $this->stalledRun('AI6-026-STALL-SWITCH');
        $run = $stalled['run'];
        $request = $stalled['request'];
        $boundSlot = $request->bound_agent_slot;
        $resultsBefore = ReviewResult::query()->where('run_id', $run->id)
            ->orderBy('id')->get(['id', 'slot_id', 'diff_hash'])->toArray();

        $approver = $this->approver($run);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $approver,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'switch_reviewer',
            $this->authorization($approver, $request, 'switch_reviewer'),
        );

        // AC-07: the switch is a new slot revision with the old results intact.
        $old = RunAgent::query()->where('run_id', $run->id)->where('slot_id', $boundSlot)->sole();
        self::assertFalse($old->is_active);
        $revision = RunAgent::query()->where('run_id', $run->id)
            ->where('approval_slot_id', $old->approval_slot_id ?? $old->slot_id)
            ->where('is_active', true)->sole();
        self::assertSame($old->slot_revision + 1, $revision->slot_revision);
        self::assertNotSame($boundSlot, $revision->slot_id);
        self::assertSame($resultsBefore, ReviewResult::query()->where('run_id', $run->id)
            ->orderBy('id')->get(['id', 'slot_id', 'diff_hash'])->toArray());

        // The resolution continues the bound fix step; the unchanged
        // fingerprints do not park the resumed run again.
        $fix = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $fix->state, (string) $fix->failure_code);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
    }

    /**
     * A round that carries valid results of two slot revisions of the same
     * approval slot stays complete: completeness counts approval slots, not
     * slot revisions, so the stall detection survives a mid-round switch.
     */
    public function test_a_mid_round_reviewer_revision_keeps_the_round_complete(): void
    {
        $stalled = $this->stalledRun('AI6-026-STALL-REVISION');
        $run = $stalled['run'];
        $fingerprints = $this->app->make(ReviewStallFingerprint::class);
        $before = $fingerprints->completedRound($run, 2);
        self::assertIsString($before);

        // A second revision of the same approval slot delivers one more valid
        // result for the identical round, checkpoint and diff.
        $template = ReviewResult::query()->where('run_id', $run->id)->where('round_number', 2)
            ->where('invocation_outcome', 'valid_result')->firstOrFail();
        $old = RunAgent::query()->where('run_id', $run->id)->where('slot_id', $template->slot_id)->sole();
        $revisionSlotId = (string) Str::uuid();
        RunAgent::query()->create([
            'run_id' => $run->id,
            'slot_id' => $revisionSlotId,
            'approval_slot_id' => $old->approval_slot_id ?? $old->slot_id,
            'slot_revision' => $old->slot_revision + 1,
            'is_active' => true,
            'role' => 'quality_review',
            'provider_profile' => $old->provider_profile,
            'model' => $old->model,
            'effort' => $old->effort,
            'prompt_profile' => $old->prompt_profile,
        ]);
        RunAgent::query()->whereKey($old->id)->update(['is_active' => false]);
        $copy = $template->replicate();
        $copy->id = (string) Str::uuid();
        $copy->slot_id = $revisionSlotId;
        $copy->session_id = (string) Str::uuid();
        $copy->save();

        // Three valid results, two approval slots, two snapshot reviewers: the
        // round stays complete and the fingerprint stays identical.
        $after = $fingerprints->completedRound($run, 2);
        self::assertSame($before, $after);
    }

    /**
     * Drive the real loop into the first repeated round: identical findings in
     * two complete consecutive rounds over an unchanged diff. The fix turn is
     * a genuine no-change turn, so round two reviews the identical tree.
     *
     * @return array{run: Run, request: HumanRequest}
     */
    private function stalledRun(string $ticketId): array
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun($ticketId);
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 1));

        $fix = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $fix->state, (string) $fix->failure_code);

        $run = $this->completeUnchangedCheckRound($run, $identifier, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        // Both complete rounds carry the same finding groups and the same diff.
        $hashes = ReviewResult::query()->where('run_id', $run->id)
            ->where('invocation_outcome', 'valid_result')
            ->pluck('diff_hash')->unique()->all();
        self::assertCount(1, $hashes, 'The stall fixture requires an unchanged diff across both rounds.');
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 2));

        // The first repetition parks before the next agent call.
        $gate = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::WAITING, $gate->state, (string) $gate->failure_code);
        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::REVIEW_LIMIT, $fresh->wait_reason);
        $request = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame('review_limit', $request->kind);
        self::assertEqualsCanonicalizing(
            ['additional_round', 'switch_reviewer', 'finding_disposition'],
            $request->allowed_effects,
        );

        return ['run' => $run, 'request' => $request];
    }

    private function projectIdentifier(Run $run): string
    {
        return (string) Project::query()->findOrFail($run->project_id)->project_identifier;
    }

    private function approver(Run $run): User
    {
        return ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::APPROVER->value)->firstOrFail()->user()->firstOrFail();
    }

    private function authorization(User $actor, HumanRequest $request, string $effect): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('stall-resume-'.$actor->id.'-'.bin2hex(random_bytes(4)));
        $session->start();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);

        return InterventionAuthorization::consumeFresh(
            $proof,
            $actor,
            $guard,
            HumanRequestAnswerController::STEP_UP_ACTION,
            [$request->run_id, $request->id, $request->bound_run_version, $effect],
        );
    }
}
