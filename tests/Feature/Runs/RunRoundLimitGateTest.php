<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunLimitConsumption;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-01 for the round limits of RUN-006: the review and fix round counters
 * continue at the boundary and stop one above it before the provider call,
 * without partial persistence.
 */
final class RunRoundLimitGateTest extends TicketUiTestCase
{
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    public function test_the_review_round_limit_continues_at_the_boundary_and_parks_one_above(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-026-REVLIMIT');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);
        $this->overrideLimits($run, ['max_review_rounds' => 1]);

        $adapter = $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);

        // At the boundary the round still runs: it consumes exactly the limit.
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeUnchangedCheckRound($run, $identifier, 2);

        // One above stops before the provider call and before any effect.
        $turnsBefore = $adapter->turnCount;
        $gate = $this->executeReviewRound($run, 2);
        self::assertSame(ExecutionJobState::WAITING, $gate->state, (string) $gate->failure_code);
        self::assertSame($turnsBefore, $adapter->turnCount);
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)
            ->where('round_number', 2)->count());

        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::REVIEW_LIMIT, $fresh->wait_reason);
        $pending = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', 'limit_pending')->orderByDesc('sequence')->firstOrFail();
        self::assertSame('max_review_rounds', $pending->redacted_metadata['limit']);
        self::assertSame(2, $pending->redacted_metadata['observed']);
        self::assertSame(1, $pending->redacted_metadata['maximum']);
        self::assertSame('review_limit', HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole()->kind);
    }

    public function test_the_fix_round_limit_continues_at_the_boundary_and_parks_one_above(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-026-FIXLIMIT');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);
        $this->overrideLimits($run, ['max_fix_rounds' => 1]);

        $adapter = $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        // At the boundary the first fix turn still runs.
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeUnchangedCheckRound($run, $identifier, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 2));

        // One above stops before the provider call of the second fix turn.
        $turnsBefore = $adapter->turnCount;
        $gate = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::WAITING, $gate->state, (string) $gate->failure_code);
        self::assertSame($turnsBefore, $adapter->turnCount);

        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::REVIEW_LIMIT, $fresh->wait_reason);
        $pending = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', 'limit_pending')->orderByDesc('sequence')->firstOrFail();
        self::assertSame('max_fix_rounds', $pending->redacted_metadata['limit']);
        self::assertSame(2, $pending->redacted_metadata['observed']);
        self::assertSame(1, $pending->redacted_metadata['maximum']);
    }

    public function test_the_verification_round_limit_runs_at_the_boundary_and_parks_one_above(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-043-VERIFICATION-LIMIT');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);
        $this->overrideLimits($run, ['max_verification_rounds' => 1]);
        $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ]);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->stepJob($run, ExecutionStepType::VERIFY, 1)->fresh()->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeUnchangedCheckRound($run, $identifier, 2);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2, ExecutionJobState::WAITING)->state);
        $verification = $this->stepJob($run, ExecutionStepType::VERIFY, 2)->fresh();
        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        self::assertSame(RunState::WAITING, $run->fresh()->state);
        self::assertSame(WaitReason::REVIEW_LIMIT, $run->fresh()->wait_reason);
        $request = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame('review_limit', $request->kind);
        self::assertSame(
            'finding_verification',
            RunAgent::query()->where('run_id', $run->id)->where('slot_id', $request->bound_agent_slot)->sole()->role,
        );
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'finding_verification')->where('round_number', 2)->count());
        $pending = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', 'limit_pending')->orderByDesc('sequence')->firstOrFail();
        self::assertSame('max_verification_rounds', $pending->redacted_metadata['limit']);
        self::assertSame(2, $pending->redacted_metadata['observed']);
        self::assertSame(1, $pending->redacted_metadata['maximum']);

        $verify = $this->stepJob($run, ExecutionStepType::VERIFY, 2);
        self::assertSame($verify->idempotency_key, $request->bound_step_key);
        self::assertSame(1, $this->verificationRoundsConsumed($run));

        // A repeated delivery of the parked step is free and remains bound to
        // verify:2. It neither consumes the rejected round nor calls a provider.
        $this->executeReviewRound($run, 2, ExecutionJobState::WAITING);
        self::assertSame(ExecutionJobState::WAITING, $verify->fresh()->state);
        self::assertSame(1, $this->verificationRoundsConsumed($run));
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'finding_verification')->where('round_number', 2)->count());

        // A profile revision resumes the exact same verification step. The
        // unchanged round limit parks it again without charging another round.
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
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(ExecutionJobState::PLANNED, $verify->fresh()->state);
        self::assertSame(1, $this->verificationRoundsConsumed($run));

        $this->executeReviewRound($run, 2, ExecutionJobState::WAITING);
        self::assertSame(ExecutionJobState::WAITING, $verify->fresh()->state);
        self::assertSame(1, $this->verificationRoundsConsumed($run));
        $grantRequest = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame($verify->idempotency_key, $grantRequest->bound_step_key);

        // The explicit one-round grant raises only the approved verification
        // limit and resumes verify:2. Its first successful consumption reaches,
        // but cannot exceed, the immutable server maximum.
        $this->app->make(HumanRequestService::class)->answer(
            $grantRequest,
            $approver,
            $grantRequest->bound_run_version,
            $grantRequest->bound_ticket_contract,
            $grantRequest->bound_checkpoint,
            $grantRequest->bound_scope,
            $grantRequest->bound_agent_slot,
            $grantRequest->bound_requested_effect,
            'additional_round',
            $this->authorization($approver, $grantRequest, 'additional_round'),
        );
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(ExecutionJobState::PLANNED, $verify->fresh()->state);
        self::assertSame(2, $this->app->make(RunLimitPolicy::class)
            ->effective($run->fresh())[ImportLimit::MAX_VERIFICATION_ROUNDS->value]);

        $this->executeReviewRound($run, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $verify->fresh()->state);
        self::assertSame(2, $this->verificationRoundsConsumed($run));
        self::assertSame(1, ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'finding_verification')->where('round_number', 2)
            ->where('invocation_outcome', 'valid_result')->count());
    }

    private function projectIdentifier(Run $run): string
    {
        return (string) Project::query()->findOrFail($run->project_id)->project_identifier;
    }

    /** @param array<string, int> $limits */
    private function overrideLimits(Run $run, array $limits): void
    {
        $snapshot = $run->agent_profile_snapshot ?? [];
        $snapshot['limits'] = array_merge(is_array($snapshot['limits'] ?? null) ? $snapshot['limits'] : [], $limits);
        DB::table('runs')->where('id', $run->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    private function verificationRoundsConsumed(Run $run): int
    {
        return (int) RunLimitConsumption::query()->where('run_id', $run->id)
            ->where('limit_name', ImportLimit::MAX_VERIFICATION_ROUNDS->value)->sum('quantity');
    }
}
