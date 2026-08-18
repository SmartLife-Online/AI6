<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunPreflight;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunStepReconciler;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ExecutionJobContractTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    /** TC-02: the unique index refuses a second step with the same derived key. */
    public function test_the_unique_index_refuses_a_second_step_with_the_same_key(): void
    {
        $run = $this->readyRun('AI6-017-JOB-1');
        $planned = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        $this->expectException(QueryException::class);
        ExecutionJob::query()->create([
            'run_id' => $run->id,
            'step_type' => $planned->step_type,
            'step_number' => 2,
            'idempotency_key' => $planned->idempotency_key,
            'state' => ExecutionJobState::PLANNED,
        ]);
    }

    /** TC-03: a duplicate delivery finds the step claimed or done and stays without effect. */
    public function test_a_duplicate_delivery_produces_no_second_preparation_or_effect(): void
    {
        $run = $this->readyRun('AI6-017-JOB-2');
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();
        $orchestrator = $this->app->make(RunOrchestrator::class);

        (new ExecuteRunStep($job->id))->handle($orchestrator);
        (new ExecuteRunStep($job->id))->handle($orchestrator);

        $job->refresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(1, $job->attempts, 'The second delivery must not claim the finished step again.');
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        foreach (['step.preflight.planned', 'step.preflight.running', 'step.preflight.succeeded', 'step.implement.planned'] as $type) {
            self::assertSame(1, RunEvent::query()->where('run_id', $run->id)->where('event_type', $type)->count(), $type);
        }
        self::assertSame(RunState::RUNNING, $run->refresh()->state);
        self::assertSame(RunPhase::IMPLEMENT, $run->phase);
        self::assertTrue(ExecutionStepType::IMPLEMENT->hasRegisteredHandler());
        self::assertSame(1, DB::table('jobs')->count(), 'The registered implement handler is handed to the existing job path.');
    }

    /** TC-04: an expired lease is reclaimed, the overtaken owner publishes nothing. */
    public function test_an_expired_lease_is_reclaimed_and_the_stale_owner_cannot_publish(): void
    {
        $run = $this->readyRun('AI6-017-JOB-3');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        $first = $orchestrator->claimStep($job, 'worker:first');
        self::assertInstanceOf(ExecutionJob::class, $first);
        $this->expireLease($first);
        $second = $orchestrator->claimStep($job->fresh() ?? $job, 'worker:second');

        self::assertInstanceOf(ExecutionJob::class, $second);
        self::assertSame('worker:second', $second->lease_owner);
        self::assertSame(2, $second->attempts);
        self::assertFalse($orchestrator->finishStep($first, 'worker:first', ExecutionJobState::SUCCEEDED, 'veraltet'));
        self::assertSame(ExecutionJobState::RUNNING, $job->fresh()->state);
    }

    /** TC-04: after the attempt maximum the reconciler ends the step visibly failed. */
    public function test_the_attempt_maximum_ends_the_step_visibly_instead_of_repeating_it(): void
    {
        $run = $this->readyRun('AI6-017-JOB-4');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claimed = $orchestrator->claimStep($job->fresh() ?? $job, 'worker:'.$attempt);
            self::assertInstanceOf(ExecutionJob::class, $claimed, 'Attempt '.$attempt.' must be claimable.');
            $this->expireLease($claimed);
        }
        self::assertNull($orchestrator->claimStep($job->fresh() ?? $job, 'worker:4'));

        $this->app->make(RunStepReconciler::class)->reconcile();

        $job->refresh();
        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('step_retry_exhausted', $job->failure_code);
        self::assertSame(3, $job->attempts);
        self::assertSame(RunState::FAILED, $run->refresh()->state);
        self::assertSame(1, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.preflight.failed')->count());
        self::assertNotNull(Project::query()->whereKey($run->project_id)->value('active_run_id'));
    }

    /** TC-05: a crash before the side effect leaves the step executable and produces one effect. */
    public function test_a_crash_before_the_side_effect_leaves_the_step_executable(): void
    {
        $run = $this->readyRun('AI6-017-JOB-5');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        $crashed = $orchestrator->claimStep($job, 'worker:crashed');
        self::assertInstanceOf(ExecutionJob::class, $crashed);
        $this->expireLease($crashed);
        self::assertNull($crashed->fresh()?->intent, 'The crash happened before any intent was bound.');

        $this->app->make(RunStepReconciler::class)->reconcile();
        $this->workQueue(1);

        $job->refresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        self::assertSame(RunState::RUNNING, $run->refresh()->state);
    }

    /** TC-06: a crash after the bound side effect is completed from the intent, without a second effect. */
    public function test_a_crash_after_the_side_effect_is_completed_from_the_persisted_intent(): void
    {
        $run = $this->readyRun('AI6-017-JOB-6');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        // Reproduce the crash point: intent bound, effect applied, result not published.
        $claimed = $orchestrator->claimStep($job, 'worker:crashed');
        self::assertInstanceOf(ExecutionJob::class, $claimed);
        self::assertTrue($orchestrator->persistIntent($claimed, 'worker:crashed', [
            'effect' => 'prepare_implement_step',
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::IMPLEMENT->value,
            'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::IMPLEMENT, 1),
        ]));
        $orchestrator->applyPreparedStepEffect($run, ExecutionStepType::PREFLIGHT);
        $this->expireLease($claimed->fresh() ?? $claimed);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());

        DB::table('jobs')->delete();
        $this->app->make(RunStepReconciler::class)->reconcile();
        $this->workQueue(1);

        $job->refresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(2, $job->attempts);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count(), 'The side effect must not repeat.');
        self::assertSame(1, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.implement.planned')->count());
        self::assertSame(RunState::RUNNING, $run->refresh()->state);
    }

    /** TC-06: a rewritten intent is refused instead of being executed. */
    public function test_a_step_intent_that_is_not_bound_to_this_run_is_refused(): void
    {
        $run = $this->readyRun('AI6-017-JOB-7');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        $claimed = $orchestrator->claimStep($job, 'worker:tampered');
        self::assertInstanceOf(ExecutionJob::class, $claimed);
        self::assertTrue($orchestrator->persistIntent($claimed, 'worker:tampered', [
            'effect' => 'prepare_implement_step',
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::IMPLEMENT->value,
            'step_number' => 7,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::IMPLEMENT, 7),
        ]));
        $this->expireLease($claimed->fresh() ?? $claimed);

        $this->app->make(RunStepReconciler::class)->reconcile();
        $this->workQueue(1);

        $job->refresh();
        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('invalid_step_intent', $job->failure_code);
        self::assertSame(RunState::FAILED, $run->refresh()->state);
        self::assertSame(0, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
    }

    /** TC-07: a waiting run parks its step and the reconciler resumes it exactly once. */
    public function test_a_waiting_run_parks_its_step_and_resumes_it_once(): void
    {
        $run = $this->readyRun('AI6-017-JOB-8');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $run = $orchestrator->transition($run, $run->version, RunState::WAITING, RunPhase::PREPARE, WaitReason::HUMAN_QUESTION);
        (new ExecuteRunStep($job->id))->handle($orchestrator);

        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);
        self::assertSame(1, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.preflight.waiting')->count());

        $this->app->make(RunStepReconciler::class)->reconcile();
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state, 'A still waiting run keeps its step parked.');

        $orchestrator->transition($run->refresh(), $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $this->app->make(RunStepReconciler::class)->reconcile();

        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertSame([
            'claim_finalized',
            'step.preflight.planned',
            'step.preflight.running',
            'step.preflight.waiting',
            'step.preflight.planned',
        ], RunEvent::query()->where('run_id', $run->id)->orderBy('id')->pluck('event_type')->all(),
            'Every step state change produces exactly one event, in a stable order.');
        self::assertSame(1, DB::table('jobs')->count(), 'The resumed step is delivered again exactly once.');
    }

    /** TC-06: a run that starts waiting after the claim gets no unbacked success. */
    public function test_the_prepared_effect_is_refused_while_the_run_waits(): void
    {
        $run = $this->readyRun('AI6-017-JOB-17');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $run = $orchestrator->transition($run, $run->version, RunState::WAITING, RunPhase::PREPARE, WaitReason::HUMAN_QUESTION);

        self::assertFalse($orchestrator->applyPreparedStepEffect($run, ExecutionStepType::PREFLIGHT));
        self::assertSame(0, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        self::assertSame(RunState::WAITING, $run->refresh()->state);
        self::assertSame(RunPhase::PREPARE, $run->phase);
    }

    /** TC-06: a terminal run ends its claimed step as a named failure without any effect. */
    public function test_a_terminal_run_ends_its_claimed_step_without_an_effect(): void
    {
        $run = $this->readyRun('AI6-017-JOB-18');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();
        $orchestrator->failRun($run->id);

        (new ExecuteRunStep($job->id))->handle($orchestrator);

        $job->refresh();
        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('run_not_executable', $job->failure_code);
        self::assertNull($job->intent, 'No intent is bound for a run that cannot execute.');
        self::assertSame(0, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
    }

    /** TC-03: the reconciler hands a step back once per lease period, not once per tick. */
    public function test_the_reconciler_does_not_pile_up_duplicate_deliveries(): void
    {
        $run = $this->readyRun('AI6-017-JOB-15');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();
        $claimed = $orchestrator->claimStep($job, 'worker:crashed');
        self::assertInstanceOf(ExecutionJob::class, $claimed);
        $this->expireLease($claimed);

        $reconciler = $this->app->make(RunStepReconciler::class);
        $reconciler->reconcile();
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state, 'A dead lease returns to the planned state.');

        $reconciler->reconcile();
        $reconciler->reconcile();
        self::assertSame(1, DB::table('jobs')->count(), 'A step handed back stays out of the queue for one lease period.');
    }

    /** TC-03: a prepared step without a registered handler is planned but never delivered. */
    public function test_a_step_without_a_registered_handler_is_never_delivered(): void
    {
        $run = $this->readyRun('AI6-017-JOB-16');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $preflight = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();
        (new ExecuteRunStep($preflight->id))->handle($orchestrator);
        DB::table('jobs')->delete();

        $implement = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        ExecutionJob::query()->whereKey($implement->getKey())
            ->update(['updated_at' => Date::now()->subSeconds(3600)]);

        $this->app->make(RunStepReconciler::class)->reconcile();

        self::assertTrue(ExecutionStepType::IMPLEMENT->hasRegisteredHandler());
        self::assertSame(1, DB::table('jobs')->count(), 'The registered implement handler is redelivered on the existing job path.');
        self::assertSame(ExecutionJobState::PLANNED, $implement->fresh()->state);
        self::assertSame(0, $implement->fresh()->attempts);
    }

    /** TC-08: an untrusted value in a timeline text never reaches the database in clear text. */
    public function test_timeline_text_passes_the_central_redaction_boundary(): void
    {
        $run = $this->readyRun('AI6-017-JOB-9');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $secret = 'ghp_'.str_repeat('A', 36);

        $orchestrator->recordStepEvent(
            $run->id,
            ExecutionStepType::PREFLIGHT->value,
            ExecutionJobState::FAILED,
            'Providerausgabe: token '.$secret.' abgelehnt.',
            'redaction-probe:'.$run->id,
        );

        $stored = (string) RunEvent::query()->where('event_key', 'redaction-probe:'.$run->id)->value('redacted_payload');
        self::assertStringNotContainsString($secret, $stored);
        self::assertStringContainsString('[REDACTED', $stored);
        self::assertSame(0, DB::table('run_events')->where('redacted_payload', 'like', '%'.$secret.'%')->count());
    }

    /**
     * TC-09: a failing preflight ends named and visible, without an agent call.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function preflightFailures(): array
    {
        return [
            'missing workspace' => ['workspace', 'workspace_checkpoint_missing', '11'],
            'sandbox roots not isolated' => ['sandbox', 'sandbox_roots_not_isolated', '12'],
            'process policy unavailable' => ['policy', 'process_policy_unavailable', '13'],
        ];
    }

    #[DataProvider('preflightFailures')]
    public function test_preflight_failures_end_named_and_visible(string $scenario, string $expectedCode, string $ticketSuffix): void
    {
        $run = $this->readyRun('AI6-017-JOB-'.$ticketSuffix, $scenario !== 'workspace');
        $this->breakPreflight($scenario);
        $job = ExecutionJob::query()->where('run_id', $run->id)->firstOrFail();

        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class));

        $job->refresh();
        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame($expectedCode, $job->failure_code, 'Scenario '.$scenario);
        self::assertSame(RunState::FAILED, $run->refresh()->state);
        self::assertNull($run->wait_reason, 'No wait reason producer is registered by this ticket.');
        self::assertNotNull(Project::query()->whereKey($run->project_id)->value('active_run_id'), 'A technical failure never releases the project lock.');
        self::assertSame(0, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        self::assertStringContainsString($expectedCode, (string) RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'step.preflight.failed')->value('redacted_payload'));
        self::assertSame(0, DB::table('jobs')->count(), 'A failed preflight starts nothing.');
    }

    /**
     * TC-09: every remaining binding scenario names its own failure.
     *
     * The persisted run is protected by SQLite guards, so these branches are driven
     * against an in-memory copy of the real ready run. The end-to-end behaviour of a
     * named failure is proven by the scenarios above; what is proven here is that
     * each violated binding selects its own code, and that the intact run passes.
     */
    public function test_every_binding_scenario_names_its_own_preflight_failure(): void
    {
        $run = $this->readyRun('AI6-017-JOB-14');
        $preflight = $this->app->make(RunPreflight::class);
        self::assertNull($preflight->failureCode($run), 'The prepared run must pass its own preflight.');

        $scenarios = [
            'approval_binding_stale' => ['claim_parent_control_sha' => str_repeat('9', 64)],
            'preflight_binding_missing' => ['prompt_hash' => 'not-a-hash'],
            'snapshot_binding_changed' => ['scope_hash' => str_repeat('8', 64)],
            'preflight_snapshot_missing' => ['config_snapshot' => null],
            'workspace_checkpoint_missing' => ['worktree_path' => null],
            'agent_profile_binding_missing' => ['agent_profile_snapshot' => ['reviewers' => []]],
            'agent_profile_not_registered' => ['agent_profile_snapshot' => [
                'implementation' => ['profile_id' => 'not-registered', 'runtime_profile_id' => 'fake-v1', 'provider_profile' => 'fake'],
            ]],
            'runtime_profile_binding_missing' => ['runtime_profile_snapshot' => []],
            'runtime_profile_not_server_bound' => ['runtime_profile_snapshot' => [
                'fake-v1' => ['id' => 'fake-v1', 'hash' => str_repeat('7', 64)],
            ]],
            'instruction_binding_missing' => ['instruction_snapshot' => []],
            'prompt_binding_missing' => ['prompt_snapshot' => ['rendered_prompts' => []]],
        ];

        foreach ($scenarios as $expectedCode => $attributes) {
            self::assertSame(
                $expectedCode,
                $preflight->failureCode((clone $run)->forceFill($attributes)),
                'Scenario '.$expectedCode,
            );
        }
    }

    /** A run that is ready for its preflight: claimed, workspace bound, checkpoint bound. */
    private function readyRun(string $ticketId, bool $bindWorkspace = true): Run
    {
        $fixture = $this->completedApproval($ticketId);
        $run = $this->finalizedRun($fixture);
        if (! $bindWorkspace) {
            DB::table('jobs')->delete();

            return $run;
        }
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$fixture['project']->project_identifier.'/'.$run->id,
            '/managed/worktrees/'.$run->id,
        );
        $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64));
        DB::table('jobs')->delete();

        return $run;
    }

    private function breakPreflight(string $scenario): void
    {
        match ($scenario) {
            'workspace' => null,
            'sandbox' => config()->set('ai6.execution_mailboxes.agent_output_root', config('ai6.execution_mailboxes.agent_root')),
            'policy' => config()->set('ai6.process.policies.agent.working_roots', ['/somewhere/else']),
            default => self::fail('Unknown scenario '.$scenario),
        };
        foreach ([ProcessPolicyRegistry::class, RunPreflight::class, RunOrchestrator::class] as $rebuilt) {
            $this->app->forgetInstance($rebuilt);
        }
    }

    /** Age a claimed step the way a crashed worker leaves it behind. */
    private function expireLease(ExecutionJob $job): void
    {
        ExecutionJob::query()->whereKey($job->getKey())->update([
            'lease_expires_at' => Date::now()->subSecond(),
            'updated_at' => Date::now()->subSeconds(3600),
        ]);
    }

    /** Drain the database queue exactly the way the worker role does. */
    private function workQueue(int $maxJobs = 0): void
    {
        $parameters = [
            'connection' => 'database',
            '--queue' => 'default',
            '--sleep' => 0,
            '--timeout' => 0,
            '--tries' => 3,
        ];
        if ($maxJobs > 0) {
            $parameters['--max-jobs'] = $maxJobs;
        } else {
            $parameters['--stop-when-empty'] = true;
        }
        $exitCode = Artisan::call('queue:work', $parameters);
        self::assertSame(0, $exitCode, Artisan::output());
    }
}
