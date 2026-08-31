<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Reviews\SecurityReviewStep;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunFinalizationStep;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Runs\BuildsFixLoopFixture;

/** Drives one real publish candidate into its worker-bound security step. */
trait BuildsSecurityReviewFixture
{
    use BuildsCheckFixture, BuildsReviewRoundFixture {
        BuildsReviewRoundFixture::approvalSelection insteadof BuildsCheckFixture;
    }
    use BuildsFixLoopFixture;

    /**
     * @param  array<string, string>  $candidateFiles
     * @param  list<InstructionCandidate>  $instructions
     * @return array{run: Run, worktree: string}
     */
    protected function preparedSecurityReview(
        string $ticketId,
        array $candidateFiles = [],
        array $instructions = [],
    ): array {
        Queue::fake();
        $this->configureSecurityChecks();
        $prepared = $this->preparedReviewRun($ticketId, $instructions);
        $run = $prepared['run'];
        $identifier = (string) $run->project()->value('project_identifier');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        foreach ($candidateFiles as $relative => $content) {
            $target = $prepared['worktree'].'/'.str_replace('\\', '/', $relative);
            if (! is_dir(dirname($target))) {
                self::assertTrue(mkdir(dirname($target), 0700, true));
            }
            self::assertNotFalse(file_put_contents($target, $content));
        }
        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $this->seedSecurityBeforeReviewCheck($run->fresh());
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);
        $this->bindSecurityRepository($run->fresh(), $prepared['worktree']);

        return ['run' => $run->fresh(), 'worktree' => $prepared['worktree']];
    }

    protected function executeSecurityReview(Run $run): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $this->app->forgetInstance(SecurityReviewStep::class);
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        return $job->fresh() ?? $job;
    }

    private function configureSecurityChecks(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['--version'])]);
        config(['ai6.checks.profiles.probe-final' => $this->probeProfile(['--version'], phases: ['final'])]);
    }

    private function seedSecurityBeforeReviewCheck(Run $run): Run
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

    private function bindSecurityRepository(Run $run, string $worktree): void
    {
        $managed = $this->implementationTemp('security-managed');
        $identifier = (string) $run->project()->value('project_identifier');
        $repository = $managed.'/projects/'.$identifier.'/repository';
        self::assertTrue(mkdir(dirname($repository), 0700, true));
        $common = $this->gitOutput(['rev-parse', '--path-format=absolute', '--git-common-dir'], $worktree);
        self::assertTrue($this->app->make(Filesystem::class)->copyDirectory(dirname($common), $repository));
        $this->app->instance(ControlOperationConfiguration::class, new ControlOperationConfiguration(
            $managed, $managed.'/keys', PHP_BINARY, PHP_BINARY, 120, 30, 30, 3,
            $managed.'/known-hosts', ['refs/heads/main'], 300, 8,
        ));
        $this->app->forgetInstance(ManagedProjectPath::class);
    }
}
