<?php

namespace Tests\Feature\Runs;

use App\AI6\Checks\CheckerExecutionProcessor;
use App\AI6\Checks\CheckFailureResolver;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\RunCheckpointService;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunCheckpoint;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunStepReconciler;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\After;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-09: the check step runs over the existing idempotent job path, and a
 * failing check parks the run behind its one check_failure producer.
 * TC-08: the executed profile set comes from the bound configuration snapshot.
 */
final class CheckStepTest extends TicketUiTestCase
{
    use BuildsCheckFixture;
    use BuildsRunWorkspaceGitFixture;

    #[After]
    public function removeGitRunnerFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    /** The implement step hands over to a planned check step in the check phase. */
    public function test_a_finished_implement_step_plans_the_check_step_in_the_check_phase(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-PLAN', ['probe-ok']);

        $implemented = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $implemented->state, (string) $implemented->failure_code);

        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(RunPhase::CHECK, $run->phase);
        self::assertSame(RunState::RUNNING, $run->state);
        self::assertNotNull($this->checkJob($run));
    }

    /** TC-09: the same step message delivered twice starts one process and stores one result. */
    public function test_the_same_step_message_twice_produces_exactly_one_result(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-STEP-IDEMPOTENT', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $this->deliver($job);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->fresh()?->state, (string) $job->fresh()?->failure_code);
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());

        // A redelivery of a finished step returns without touching anything.
        $this->deliver($job);
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());

        // Even a step forced back to planned produces no second result, because
        // the execution key already binds run, phase, profile and tree.
        ExecutionJob::query()->whereKey($job->getKey())->update(['state' => ExecutionJobState::PLANNED, 'lease_owner' => null, 'lease_expires_at' => null]);
        $this->deliver($job);
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());
    }

    /** TC-09: a failed check parks the run behind check_failure instead of ending it. */
    public function test_a_failed_check_parks_the_run_behind_check_failure(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-fail' => $this->probeProfile(['ai6-check-fail.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-STEP-FAIL', ['probe-fail']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $this->deliver($job);

        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::CHECK_FAILURE, $run->wait_reason);
        self::assertSame(RunPhase::CHECK, $run->phase);
        // The step stays parked so a resolver can pick it up again.
        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->fresh()?->state);
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $run->id)->count());

        // The registered retry resolver reaches an executable run again.
        $resumed = $this->app->make(RunOrchestrator::class)
            ->resumeCheckFailure($run, $run->version, $job->idempotency_key);
        self::assertSame(RunState::RUNNING, $resumed->state);
        self::assertNull($resumed->wait_reason);
    }

    /**
     * AC-11: the retry resolver really re-executes on the unchanged tree.
     *
     * The failed predecessor is superseded, the resumed step starts a second
     * checker process against the same tree, and the run reaches a real end
     * state instead of parking again until its attempts are exhausted.
     */
    public function test_the_retry_resolver_runs_the_check_again_on_the_unchanged_tree(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-flaky' => $this->probeProfile(['ai6-check-flaky.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-RETRY', ['probe-flaky']);
        $worktree = $prepared['worktree'];
        // The script fails once and succeeds afterwards. Its marker lives
        // outside the checked tree, so the tree binding is genuinely unchanged
        // between both executions.
        $marker = str_replace('\\', '/', $this->implementationTemp('retry-marker')).'/AI6-021-RETRY';
        @unlink($marker);
        self::assertNotFalse(file_put_contents($worktree.'/ai6-check-flaky.php', sprintf(
            "<?php\n\n\$marker = %s;\nif (! file_exists(\$marker)) {\n    file_put_contents(\$marker, \"1\\n\");\n    fwrite(STDERR, \"first run fails\\n\");\n    exit(1);\n}\nfwrite(STDOUT, \"retry succeeded\\n\");\nexit(0);\n",
            var_export($marker, true),
        )));
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $this->deliver($job);
        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(WaitReason::CHECK_FAILURE, $run->wait_reason);
        $failed = CheckResultRecord::query()->where('run_id', $run->id)->sole();
        self::assertSame(CheckResultState::FAILED, $failed->state);
        $boundTree = $failed->tree_sha;

        $this->app->make(CheckFailureResolver::class)
            ->retryOnUnchangedTree($run, $run->version, $job->idempotency_key, CheckPhase::BEFORE_REVIEW);

        // The predecessor is superseded, not deleted: the evidence survives.
        self::assertNotNull($failed->fresh()?->superseded_at);
        $resumed = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $resumed);
        self::assertSame(RunState::RUNNING, $resumed->state);

        $this->deliver($this->checkJob($resumed) ?? $job);

        $records = CheckResultRecord::query()->where('run_id', $run->id)->orderBy('created_at')->get();
        self::assertCount(2, $records, 'The retry must produce a second, real execution.');
        $retry = $records->last();
        self::assertSame(CheckResultState::SUCCEEDED, $retry->state);
        self::assertNull($retry->superseded_at);
        self::assertSame($boundTree, $retry->tree_sha, 'The retry ran on the unchanged tree.');
        self::assertStringContainsString('retry succeeded', $retry->redacted_output);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->checkJob($resumed)?->state);
        self::assertNull($prepared['run']->fresh()?->wait_reason);
        @unlink($marker);
    }

    /**
     * AC-10: after a retry, the redelivery binds the live row, not its
     * superseded predecessor.
     *
     * Both rows carry the same result key — the retry ran on the unchanged tree
     * — and the superseded one is the older of the two. A lookup that does not
     * exclude it would report the execution as missing and fail a run whose
     * check actually succeeded.
     */
    public function test_a_redelivery_after_a_retry_binds_the_live_result_not_the_superseded_one(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-flaky' => $this->probeProfile(['ai6-check-flaky.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-REDELIVER', ['probe-flaky']);
        $marker = str_replace('\\', '/', $this->implementationTemp('retry-marker')).'/AI6-021-REDELIVER';
        @unlink($marker);
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/ai6-check-flaky.php', sprintf(
            "<?php\n\n\$marker = %s;\nif (! file_exists(\$marker)) {\n    file_put_contents(\$marker, \"1\\n\");\n    fwrite(STDERR, \"first run fails\\n\");\n    exit(1);\n}\nfwrite(STDOUT, \"retry succeeded\\n\");\nexit(0);\n",
            var_export($marker, true),
        )));
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $this->deliver($job);
        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        $this->app->make(CheckFailureResolver::class)
            ->retryOnUnchangedTree($run, $run->version, $job->idempotency_key, CheckPhase::BEFORE_REVIEW);
        $this->deliver($this->checkJob($prepared['run']->fresh() ?? $run) ?? $job);

        $rows = CheckResultRecord::query()->where('run_id', $run->id)->orderBy('created_at')->get();
        self::assertCount(2, $rows);
        self::assertSame($rows[0]->result_key, $rows[1]->result_key, 'Both rows share the colliding key.');
        self::assertNotNull($rows[0]->superseded_at, 'The older row is the superseded one.');
        self::assertNull($rows[1]->superseded_at);

        // The lease dies and the finished step is delivered once more.
        $current = $this->checkJob($prepared['run']->fresh() ?? $run);
        self::assertInstanceOf(ExecutionJob::class, $current);
        ExecutionJob::query()->whereKey($current->getKey())->update([
            'state' => ExecutionJobState::PLANNED, 'lease_owner' => null, 'lease_expires_at' => null,
        ]);
        $this->deliver($current);

        self::assertSame(ExecutionJobState::SUCCEEDED, $current->fresh()->state, (string) $current->fresh()->failure_code);
        self::assertSame(2, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertNotSame(RunState::FAILED, $prepared['run']->fresh()?->state);
        @unlink($marker);
    }

    /**
     * A delivery that ordered its check and died before the result is a named
     * failure, not a silent skip that would let an unchecked run continue.
     */
    public function test_an_orphaned_delivery_ends_as_a_named_failure(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-ORPHAN', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        // Reproduce the crash window: the order of this very attempt exists in
        // the mailbox, while no result was ever written.
        $this->writeOrphanedOrder($prepared['run']->fresh() ?? $prepared['run'], $job->attempts + 1);

        $this->deliver($job);

        self::assertSame(ExecutionJobState::FAILED, $job->fresh()?->state);
        self::assertSame('check_execution_orphaned', $job->fresh()->failure_code);
        self::assertSame(RunState::FAILED, $prepared['run']->fresh()?->state);
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());
    }

    /** AC-09: a malformed bound checks block fails the step closed instead of passing green. */
    public function test_a_malformed_bound_checks_block_fails_the_step_closed(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-SNAPSHOT', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $snapshot = $prepared['run']->fresh()?->config_snapshot;
        self::assertIsArray($snapshot);
        unset($snapshot['values']['checks']);
        DB::table('runs')->where('id', $prepared['run']->id)
            ->update([
                'config_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'version' => DB::raw('version + 1'),
            ]);

        $this->deliver($job);

        self::assertSame(ExecutionJobState::FAILED, $job->fresh()?->state);
        self::assertSame('check_snapshot_invalid', $job->fresh()->failure_code);
        self::assertSame(RunState::FAILED, $prepared['run']->fresh()->state);
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());
    }

    /** A validly bound but empty list is the one case that may finish without a check. */
    public function test_an_empty_bound_checks_list_finishes_the_step_without_a_check(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-EMPTY', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        $snapshot = $prepared['run']->fresh()?->config_snapshot;
        self::assertIsArray($snapshot);
        $snapshot['values']['checks']['before_review'] = [];
        DB::table('runs')->where('id', $prepared['run']->id)
            ->update([
                'config_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'version' => DB::raw('version + 1'),
            ]);

        $this->deliver($job);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->fresh()?->state);
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());
    }

    /** AC-11: the registered cancel path ends a check_failure wait instead of leaving it stuck. */
    public function test_the_cancel_path_ends_a_parked_check_failure(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-fail' => $this->probeProfile(['ai6-check-fail.php'])]);
        $prepared = $this->preparedCheckRun('AI6-021-STEP-CANCEL', ['probe-fail']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);
        $this->deliver($job);

        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(WaitReason::CHECK_FAILURE, $run->wait_reason);

        $cancelled = $this->app->make(RunOrchestrator::class)->cancelCheckFailure($run, $run->version);

        self::assertNotSame(RunState::WAITING, $cancelled->state);
        self::assertNull($cancelled->wait_reason);
    }

    /** TC-08: the profile set comes from the bound snapshot, not from the worktree. */
    public function test_the_executed_profile_set_comes_from_the_bound_configuration_snapshot(): void
    {
        Mail::fake();
        $this->bindCheckRuntime([
            'probe-ok' => $this->probeProfile(['ai6-check-ok.php']),
            'probe-fail' => $this->probeProfile(['ai6-check-fail.php']),
        ]);
        $prepared = $this->preparedCheckRun('AI6-021-BOUND', ['probe-ok']);
        $this->executeImplement($prepared['run']);

        // Changing the trusted server default after the run was bound must not
        // change what this run executes.
        config(['ai6.project_config.server_defaults.checks.before_review' => ['probe-fail']]);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);
        $this->deliver($job);

        $profiles = CheckResultRecord::query()->where('run_id', $prepared['run']->id)->pluck('profile')->all();
        self::assertSame(['probe-ok'], $profiles);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->fresh()?->state);
    }

    public function test_result_polls_keep_the_execution_identity_and_do_not_consume_attempts(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-045-POLL', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        config([
            'ai6.security.profile' => SecurityProfile::STRICT->value,
            'ai6.security.measures.'.SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value => 'true',
            'ai6.security.acknowledge_reduced_mode' => 'false',
        ]);
        $this->app->forgetInstance(SecurityPolicy::class);
        $this->app->forgetInstance(CheckRunner::class);

        $this->deliver($job);
        $firstPoll = $job->fresh();
        self::assertInstanceOf(ExecutionJob::class, $firstPoll);
        $firstIntent = $firstPoll->intent;
        self::assertSame(ExecutionJobState::WAITING, $firstPoll->state);
        self::assertSame(0, $firstPoll->attempts);
        self::assertIsString($firstIntent);
        $intentDocument = json_decode($firstIntent, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($intentDocument);
        $executions = json_decode((string) $intentDocument['executions'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($executions);
        $executionId = $executions['probe-ok']['id'] ?? null;
        self::assertIsString($executionId);
        $requestPath = config('ai6.execution_mailboxes.checker_root').'/requests/'.CheckerExecutionProcessor::deliveryId($executionId).'.json';
        $requestHash = hash_file('sha256', $requestPath);
        self::assertIsString($requestHash);

        $this->app->make(RunStepReconciler::class)->reconcile();
        DB::table('jobs')->delete();
        $this->deliver($job);
        $secondPoll = $job->fresh();
        self::assertInstanceOf(ExecutionJob::class, $secondPoll);
        self::assertSame(ExecutionJobState::WAITING, $secondPoll->state);
        self::assertSame(0, $secondPoll->attempts);
        self::assertSame($firstIntent, $secondPoll->intent);
        self::assertSame($requestHash, hash_file('sha256', $requestPath));
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $prepared['run']->id)->count());
    }

    public function test_the_worker_boundary_parks_ticket_drift_without_persisting_gates(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-022-WORKER-DRIFT', ['probe-ok']);
        $this->executeImplement($prepared['run']);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);
        $readModel = TicketReadModel::query()->where('project_id', $prepared['run']->project_id)->firstOrFail();
        $changed = str_replace('Ziel des Tickets.', 'Geändertes Ziel des Tickets.', $readModel->redacted_content);
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $changed,
            $readModel->relative_path,
            TicketValidationProfile::GENERIC_V1,
        );
        self::assertNotNull($projection->contractHash);
        self::assertSame(1, DB::table('ticket_read_models')->where('id', $readModel->id)->update([
            'control_operation_id' => $prepared['run']->status_operation_id,
            'control_commit' => $prepared['run']->run_base_sha,
            'blob_sha' => hash('sha256', 'blob '.strlen($changed)."\0".$changed),
            'redacted_content' => $changed,
            'ticket_contract_sha256' => $projection->contractHash,
            'document_state' => 'valid',
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));
        config(['ai6.runtime_role' => 'worker']);

        $this->deliver($job);

        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $run->wait_reason);
        self::assertSame('blocked', $run->review_readiness_state);
        self::assertSame('ticket_contract_drift', $run->review_blockers[0]['code'] ?? null);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()?->state);
        self::assertDatabaseCount('run_gates', 0);
    }

    public function test_the_worker_recovers_a_committed_checkpoint_and_reaches_review_readiness(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-022-WORKER-READY', ['probe-ok'], true);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeImplement($prepared['run'])->state);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        // Reproduce a worker crash after Git committed the complete tree but
        // before the orchestrator bound that checkpoint revision.
        $this->gitOutput(['add', '--all', '--no-renormalize'], $prepared['worktree']);
        $this->gitOutput(['commit', '-m', 'checkpoint before simulated crash'], $prepared['worktree']);
        $runner = $this->runWorkspaceRunner($this->runWorkspaceRoot());
        $this->app->instance(HardenedGitRunner::class, $runner);
        $this->app->forgetInstance(RunCheckpointService::class);
        $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'checkpoint-recovery-test');
        $probe = $runner->canonicalRawDiff(
            $prepared['worktree'],
            $prepared['run']->initial_run_base_sha,
            $this->gitOutput(['rev-parse', 'HEAD'], $prepared['worktree']),
            $context,
        );
        self::assertTrue($probe->succeeded(), $probe->errorOutput);
        $recovered = $this->app->make(RunCheckpointService::class)->create(
            $prepared['run']->fresh() ?? $prepared['run'],
            (string) $prepared['run']->project()->value('project_identifier'),
            $context,
        );
        self::assertSame($this->gitOutput(['rev-parse', 'HEAD'], $prepared['worktree']), $recovered->checkpoint_commit_sha);
        config(['ai6.runtime_role' => 'worker']);

        $this->deliver($job);

        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        self::assertSame(RunState::RUNNING, $run->state, (string) $job->fresh()?->failure_code);
        self::assertSame(RunPhase::REVIEW, $run->phase);
        self::assertSame('ready', $run->review_readiness_state);
        self::assertSame([], $run->review_blockers);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->fresh()?->state, (string) $job->fresh()?->failure_code);
        self::assertSame(2, RunCheckpoint::query()->where('run_id', $run->id)->count());
        self::assertSame($this->gitOutput(['rev-parse', 'HEAD'], $prepared['worktree']), $run->checkpoint_commit_sha);
    }

    public function test_the_worker_parks_unresolved_scope_for_approval_instead_of_failing_the_run(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        config(['ai6.project_config.server_defaults.scope.unlisted_paths' => 'require_approval']);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $prepared = $this->preparedCheckRun('AI6-022-WORKER-SCOPE', ['probe-ok'], true);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeImplement($prepared['run'])->state);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);
        $run = $prepared['run']->fresh() ?? $prepared['run'];
        $run = $this->app->make(RunOrchestrator::class)->bindActualChangedPaths(
            $run,
            $run->version,
            ['outside/Unresolved.php'],
            $this->app->make(CanonicalJson::class),
        );
        self::assertTrue(mkdir($prepared['worktree'].'/outside', 0700, true));
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/outside/Unresolved.php', "<?php\n"));
        $this->gitOutput(['add', '--all', '--no-renormalize'], $prepared['worktree']);
        $this->gitOutput(['commit', '-m', 'checkpoint before scope reconciliation'], $prepared['worktree']);
        $runner = $this->runWorkspaceRunner($this->runWorkspaceRoot());
        $this->app->instance(HardenedGitRunner::class, $runner);
        $this->app->forgetInstance(RunCheckpointService::class);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'scope-checkpoint-recovery-test');
        $this->app->make(RunCheckpointService::class)->create(
            $run,
            (string) $run->project()->value('project_identifier'),
            $context,
        );
        config(['ai6.runtime_role' => 'worker']);

        $this->deliver($job);

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $fresh->wait_reason);
        self::assertSame('blocked', $fresh->review_readiness_state);
        self::assertSame('scope_unresolved', $fresh->review_blockers[0]['code'] ?? null);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()?->state);
        $firstCheckEpoch = CheckResultRecord::query()->where('run_id', $fresh->id)->value('evidence_epoch');
        self::assertIsInt($firstCheckEpoch);

        $request = HumanRequest::query()->where('run_id', $fresh->id)->where('kind', 'scope_approval')->firstOrFail();
        self::assertSame(['outside/Unresolved.php'], $request->affected_paths);
        $approver = $this->createUser();
        $this->addMembership($approver, $fresh->project()->firstOrFail(), ProjectRole::APPROVER);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $approver,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'approve',
        );

        $resumed = $fresh->fresh();
        self::assertInstanceOf(Run::class, $resumed);
        self::assertSame(RunState::RUNNING, $resumed->state);
        self::assertContains('outside/Unresolved.php', $resumed->effective_scope_snapshot);
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);

        // Reproduce the productive empty checkpoint commit without requiring
        // the POSIX effect-lock runtime on this Windows proof.
        $this->gitOutput(['commit', '--allow-empty', '-m', 'checkpoint after scope approval'], $prepared['worktree']);
        $resumed = $this->app->make(RunCheckpointService::class)->create(
            $resumed,
            (string) $resumed->project()->value('project_identifier'),
            new RedactionContext((string) $resumed->project_id, $resumed->id, 'scope-resume-checkpoint-test'),
        );

        config(['ai6.runtime_role' => 'worker']);
        $resumedJob = $job->fresh();
        self::assertInstanceOf(ExecutionJob::class, $resumedJob);
        $this->deliver($resumedJob);

        $ready = $resumed->fresh();
        self::assertInstanceOf(Run::class, $ready);
        self::assertSame('ready', $ready->review_readiness_state, (string) $resumedJob->fresh()?->failure_code.' / '.($resumedJob->fresh()?->state->value ?? 'missing'));
        self::assertSame(RunPhase::REVIEW, $ready->phase);
        self::assertSame([$firstCheckEpoch, $ready->evidence_epoch], CheckResultRecord::query()->where('run_id', $ready->id)
            ->orderBy('evidence_epoch')->pluck('evidence_epoch')->all());
    }

    public function test_an_imported_path_ignored_by_the_project_cannot_disappear_from_the_checkpoint(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-022-IGNORED-CHECKPOINT', ['probe-ok'], true);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeImplement($prepared['run'])->state);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);

        self::assertNotFalse(file_put_contents($prepared['worktree'].'/.gitignore', "ignored.php\n"));
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/ignored.php', "<?php\n"));
        $run = $prepared['run']->fresh() ?? $prepared['run'];
        $run = $this->app->make(RunOrchestrator::class)->bindActualChangedPaths(
            $run,
            $run->version,
            ['ignored.php'],
            $this->app->make(CanonicalJson::class),
        );
        $this->gitOutput(['add', '--all', '--no-renormalize'], $prepared['worktree']);
        $this->gitOutput(['commit', '-m', 'checkpoint missing ignored path'], $prepared['worktree']);
        $this->app->instance(HardenedGitRunner::class, $this->runWorkspaceRunner($this->runWorkspaceRoot()));
        $this->app->forgetInstance(RunCheckpointService::class);
        config(['ai6.runtime_role' => 'worker']);

        $this->deliver($job);

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::FAILED, $fresh->state);
        self::assertSame('blocked', $fresh->review_readiness_state);
        self::assertSame('checkpoint_path_missing', $fresh->review_blockers[0]['code'] ?? null);
        self::assertSame('checkpoint_path_missing', $job->fresh()?->failure_code);
    }

    public function test_the_worker_auto_allows_an_unlisted_scope_path_and_rechecks_the_new_epoch(): void
    {
        Mail::fake();
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $prepared = $this->preparedCheckRun('AI6-022-WORKER-AUTO-SCOPE', ['probe-ok'], true);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeImplement($prepared['run'])->state);
        $job = $this->checkJob($prepared['run']->fresh() ?? $prepared['run']);
        self::assertInstanceOf(ExecutionJob::class, $job);
        $run = $prepared['run']->fresh() ?? $prepared['run'];
        $run = $this->app->make(RunOrchestrator::class)->bindActualChangedPaths(
            $run,
            $run->version,
            ['outside/Auto.php'],
            $this->app->make(CanonicalJson::class),
        );
        self::assertTrue(mkdir($prepared['worktree'].'/outside', 0700, true));
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/outside/Auto.php', "<?php\n"));
        $this->gitOutput(['add', '--all', '--no-renormalize'], $prepared['worktree']);
        $this->gitOutput(['commit', '-m', 'checkpoint before automatic scope'], $prepared['worktree']);
        $this->app->instance(HardenedGitRunner::class, $this->runWorkspaceRunner($this->runWorkspaceRoot()));
        $this->app->forgetInstance(RunCheckpointService::class);
        $run = $this->app->make(RunCheckpointService::class)->create(
            $run,
            (string) $run->project()->value('project_identifier'),
            new RedactionContext((string) $run->project_id, $run->id, 'auto-scope-checkpoint-test'),
        );
        config(['ai6.runtime_role' => 'worker']);

        $this->deliver($job);

        $extended = $run->fresh();
        self::assertInstanceOf(Run::class, $extended);
        self::assertSame(RunState::RUNNING, $extended->state);
        self::assertContains('outside/Auto.php', $extended->effective_scope_snapshot);
        self::assertSame(0, HumanRequest::query()->where('run_id', $extended->id)->count());
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertNull($job->fresh()?->intent);

        $this->gitOutput(['commit', '--allow-empty', '-m', 'checkpoint after automatic scope'], $prepared['worktree']);
        $extended = $this->app->make(RunCheckpointService::class)->create(
            $extended,
            (string) $extended->project()->value('project_identifier'),
            new RedactionContext((string) $extended->project_id, $extended->id, 'auto-scope-resume-test'),
        );
        $replanned = $job->fresh();
        self::assertInstanceOf(ExecutionJob::class, $replanned);
        $this->deliver($replanned);

        $ready = $extended->fresh();
        self::assertInstanceOf(Run::class, $ready);
        self::assertSame('ready', $ready->review_readiness_state, (string) $replanned->fresh()?->failure_code);
        self::assertSame(RunPhase::REVIEW, $ready->phase);
    }

    /**
     * A run whose bound snapshot names the check profiles under test.
     *
     * @param  list<string>  $profiles
     * @return array{run: Run, worktree: string}
     */
    private function preparedCheckRun(string $ticketId, array $profiles, bool $coherentGitBinding = false): array
    {
        config(['ai6.project_config.server_defaults.checks.before_review' => $profiles]);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);

        $prepared = $this->checkableRun($ticketId, $coherentGitBinding);
        // These AI6-021 contract tests execute the reduced in-process checker
        // probe directly; they do not impersonate the productive worker-side
        // AI6-022 readiness boundary.
        config(['ai6.runtime_role' => 'testing']);
        $snapshot = $prepared['run']->config_snapshot;
        self::assertIsArray($snapshot);
        self::assertSame($profiles, $snapshot['values']['checks']['before_review'] ?? null, 'The run snapshot must carry the profiles under test.');

        return $prepared;
    }

    /** Place the mailbox order of one delivery attempt without any result behind it. */
    private function writeOrphanedOrder(Run $run, int $deliveryAttempt): void
    {
        $tree = $this->implementationTemp('orphan-tree-'.bin2hex(random_bytes(4)));
        $this->app->make(IsolatedTreeExporter::class)->export((string) $run->worktree_path, $tree.'/tree', true);
        $key = CheckResult::key(
            $run->id,
            $run->evidence_epoch,
            CheckPhase::BEFORE_REVIEW,
            'probe-ok',
            $this->app->make(CheckTreeBinding::class)->hash($tree.'/tree'),
        );

        $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER)->write(
            MailboxMessageType::REQUEST,
            'check-'.$run->id,
            CheckRunner::deliveryId($key, $deliveryAttempt),
            "orphaned order\n",
        );
    }

    private function checkJob(Run $run): ?ExecutionJob
    {
        return ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::CHECK->value)->first();
    }

    private function deliver(ExecutionJob $job): void
    {
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            null,
            $this->app->make(RunCheckStep::class),
        );
        DB::table('jobs')->delete();
    }
}
