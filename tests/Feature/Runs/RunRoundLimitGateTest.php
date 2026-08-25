<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
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
}
