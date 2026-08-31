<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunFinalizationStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Str;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RunFinalizationStepTest extends TicketUiTestCase
{
    use BuildsCheckFixture, BuildsReviewRoundFixture {
        BuildsReviewRoundFixture::approvalSelection insteadof BuildsCheckFixture;
    }
    use BuildsFixLoopFixture;

    public function test_a_completed_fix_round_plans_and_executes_exactly_one_agent_free_finalization(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC01');
        $run = $this->withoutBoundChecks($prepared['run']);
        $identifier = (string) $run->project()->value('project_identifier');

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $adapter = $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $this->seedBeforeReviewCheck($run->fresh());
        $reviewEvidenceBefore = [
            'results' => ReviewResult::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'findings' => Finding::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'dispositions' => FindingDisposition::query()->whereIn(
                'finding_id', Finding::query()->where('run_id', $run->id)->select('id'),
            )->orderBy('id')->pluck('id')->all(),
            'checkpoint' => $run->only(['checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash']),
        ];

        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        self::assertSame(1, $finalize->step_number);
        self::assertSame(RunPhase::FINALIZE, $run->fresh()->phase);
        $turnsBefore = $adapter->turnCount;

        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );

        $finished = $run->fresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);
        self::assertNotNull($finished->candidate_tree_sha);
        self::assertNotNull($finished->candidate_diff_hash);
        self::assertSame($finished->run_base_sha, $finished->candidate_base_sha);
        self::assertSame($turnsBefore, $adapter->turnCount);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->count());
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::REVIEW->value)->where('step_number', 3)->exists());
        self::assertSame($reviewEvidenceBefore, [
            'results' => ReviewResult::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'findings' => Finding::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'dispositions' => FindingDisposition::query()->whereIn(
                'finding_id', Finding::query()->where('run_id', $run->id)->select('id'),
            )->orderBy('id')->pluck('id')->all(),
            'checkpoint' => $finished->only(['checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash']),
        ]);
    }

    public function test_an_effectively_blocking_finding_does_not_plan_finalization(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC01-BLOCKED');
        $run = $this->withoutBoundChecks($prepared['run']);
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->exists());
    }

    public function test_control_base_drift_parks_finalization_without_rewriting_run_branch_or_binding_candidate(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC14');
        $run = $this->withoutBoundChecks($prepared['run']);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        $branchBefore = $this->gitOutput(['rev-parse', (string) $run->run_branch], $prepared['worktree']);
        $run->project()->update(['control_oid' => str_repeat('f', 64)]);

        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );

        $parked = $run->fresh();
        self::assertSame(RunState::WAITING, $parked->state, (string) $finalize->fresh()->failure_code);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $parked->wait_reason);
        self::assertSame(ExecutionJobState::WAITING, $finalize->fresh()->state);
        self::assertSame($branchBefore, $this->gitOutput(['rev-parse', (string) $run->run_branch], $prepared['worktree']));
        self::assertNull($parked->candidate_tree_sha);
        self::assertNull($parked->candidate_diff_hash);
        self::assertTrue(HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->exists());
    }

    private function withoutBoundChecks(Run $run): Run
    {
        self::assertSame(['probe-ok'], $run->config_snapshot['values']['checks']['before_review'] ?? null);
        self::assertSame(['probe-final'], $run->config_snapshot['values']['checks']['final'] ?? null);

        return $run->fresh();
    }

    private function configureChecks(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['--version'])]);
        config(['ai6.checks.profiles.probe-final' => $this->probeProfile(['--version'], phases: ['final'])]);
    }

    private function seedBeforeReviewCheck(Run $run): Run
    {
        $tree = $this->app->make(CheckRunner::class)->currentTreeBinding($run);
        CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
            'evidence_epoch' => $run->evidence_epoch, 'profile' => 'probe-ok',
            'state' => CheckResultState::SUCCEEDED, 'reason' => null, 'exit_code' => 0,
            'duration_ms' => 1, 'redacted_output' => 'ok', 'tree_sha' => $tree, 'result_tree_sha' => $tree,
            'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
            'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, 'probe-ok', $tree),
        ]);

        return $run;
    }
}
