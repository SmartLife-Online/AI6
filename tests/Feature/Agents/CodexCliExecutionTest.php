<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentCapabilityPending;
use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentExecutionRequest;
use App\AI6\Agents\AgentExecutionRunner;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Auth\Models\User;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;
use Tests\Fixtures\Agents\FakeCodexBinary;
use Tests\Fixtures\Agents\NativeProviderMailbox;

/**
 * The Codex transport over the one AI6-047 seam: staging, mailbox, agent
 * consumer, result, worker import and cleanup, with the fake CLI as the
 * pinned binary (TC-01, TC-06, TC-07, TC-09, TC-10, TC-12, TC-13).
 *
 * The worker supplies no auth bytes. The agent consumes its synthetic store
 * through the production AI6-035 projection and namespace boundary.
 */
final class CodexCliExecutionTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    private string $wrappers;

    private bool $nativeMailbox = true;

    protected function usesNativeProviderMailbox(): bool
    {
        return $this->nativeMailbox;
    }

    private bool $codexImplementer = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wrappers = str_replace('\\', '/', base_path('storage/framework/testing')).'/ai6-codex-exec-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->wrappers, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->wrappers.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->wrappers);
        parent::tearDown();
    }

    /** TC-01: the one container binding resolves installed adapters and refuses unknown aliases before any process. */
    public function test_the_container_binding_resolves_codex_and_fake_and_refuses_other_aliases(): void
    {
        self::assertInstanceOf(CodexCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'codex_cli']));
        self::assertInstanceOf(FakeAgentAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'fake']));
        self::assertInstanceOf(GitHubCopilotCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'github_copilot_cli']));
        self::assertInstanceOf(GrokCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'grok_cli']));
        foreach (['not-implemented', 'unknown-provider'] as $alias) {
            try {
                $this->app->makeWith(AgentAdapter::class, ['providerAlias' => $alias]);
                self::fail('The alias '.$alias.' must not resolve.');
            } catch (AgentExecutionException $exception) {
                self::assertSame('agent_adapter_unavailable', $exception->reason);
            }
        }
        self::assertSame([], $this->app->make(CodexCliAdapter::class)->lastCommand);

        // A staged codex slot whose alias later has no adapter is refused by prepare() before any home exists.
        $prepared = $this->preparedCodexRun('AI6-033-TC01');
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $runner = $this->app->make(AgentExecutionRunner::class);
        $runner->destroy($home);
        $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->sole();
        $slot->forceFill(['provider_profile' => 'not-implemented'])->save();
        try {
            $runner->prepare($this->claim($job), $run, $slot->fresh(), $context, $prepared['worktree']);
            self::fail('An unimplemented alias must not stage a home.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_adapter_unavailable', $exception->reason);
        }
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
        self::assertSame([], $this->app->make(CodexCliAdapter::class)->lastCommand);
    }

    /** TC-07, TC-10, TC-02 over the seam: the implementation turn writes only the change output and the worker imports the patch. */
    public function test_an_implementation_turn_changes_only_the_change_output_and_the_worker_imports_the_patch(): void
    {
        Mail::fake();
        $prepared = $this->preparedCodexRun('AI6-033-TC07');
        $job = $this->executeCodexImplement($prepared['run']);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame(RunState::RUNNING, $prepared['run']->fresh()->state);
        self::assertSame("<?php\n\n// fake-codex-change\n", file_get_contents($prepared['worktree'].'/app/Example.php'));
        $answer = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->sole();
        self::assertSame('ok', $answer->redacted_metadata['state']);
        self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->redacted_metadata['usage_source']);
        self::assertSame(['input_tokens' => 120, 'cached_input_tokens' => 20, 'output_tokens' => 45], $answer->redacted_metadata['usage']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', (string) $answer->execution_id);

        $slot = RunAgent::query()->where('run_id', $prepared['run']->id)->where('role', 'implementation')->sole();
        self::assertSame('codex_cli', $slot->provider_profile);
        $command = $this->app->make(CodexCliAdapter::class)->lastCommand;
        self::assertSame('workspace-write', $this->optionValue($command, '--sandbox'));
        self::assertSame($slot->model, $this->optionValue($command, '--model'));
        self::assertContains('model_reasoning_effort="'.$slot->effort.'"', $command);
        self::assertSame('medium', $slot->effort, 'The approved effort of the run slot reaches the command line.');
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    public function test_report_expiry_during_a_turn_preserves_the_result_and_worker_patch_import(): void
    {
        Mail::fake();
        $prepared = $this->preparedCodexRun('AI6-035-EXPIRED-TURN');
        config(['ai6.fixture.expire_provider_report' => true]);
        $job = $this->executeCodexImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame("<?php\n\n// fake-codex-change\n", file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertNull(app(ProviderCapabilityReport::class)->read('codex_cli'));
        self::assertFalse(app(AgentProfileRegistry::class)->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());
        self::assertSame('ok', RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->sole()->redacted_metadata['state']);
    }

    public function test_an_existing_staging_survives_report_expiry_but_new_staging_waits(): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-EXPIRED-STAGING');
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->sole();
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('codex_cli');
        self::assertNotNull($document);
        $role = config('ai6.runtime_role');
        config(['ai6.runtime_role' => 'agent']);
        try {
            $store = app(ProviderCredentialStore::class);
            $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time() - 301, $document['boot_id']));
        } finally {
            config(['ai6.runtime_role' => $role]);
        }
        self::assertNull($reports->read('codex_cli'));
        $runner = app(AgentExecutionRunner::class);
        $claimed = $this->claim($job);
        self::assertSame($home->root, $runner->prepare($claimed, $run, $slot, $context, $prepared['worktree'])->root);
        $runner->destroy($home);
        $this->expectException(AgentCapabilityPending::class);
        $runner->prepare($claimed, $run, $slot, $context, $prepared['worktree']);
    }

    public function test_expired_start_evidence_parks_without_consuming_an_attempt_and_resumes_after_recheck(): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-STAGING-RECHECK');
        $this->expireCodexReport();
        $run = $prepared['run'];
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        $dispatch = fn () => (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), app(RunImplementation::class));
        $dispatch();
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(0, $job->attempts);
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
        $intent = $job->intent;
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
        $dispatch();
        self::assertSame($intent, $job->refresh()->intent, 'Polling must preserve the generation and deadline.');
        $this->seedProviderReports();
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
        $dispatch();
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        self::assertSame(0, $job->attempts);
        self::assertCount(1, glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
    }

    public function test_an_undispatched_home_waits_for_presence_without_recreating_its_binding(): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-UNDISPATCHED');
        [$run, $job, , $dispatched, $context] = $this->stage($prepared['run']);
        $runner = app(AgentExecutionRunner::class);
        $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->sole();
        $claimed = $this->claim($job);
        $next = new AgentResultContext($context->role, $context->promptSnapshot, $context->instructionSnapshot,
            $context->runtimeProfile, $context->criterionRefs, $context->actualDiff, $context->instructionUpdate,
            $context->initialScope, $context->expectedInstructionBlobs, $context->slotId, $context->attempt + 1,
            $context->expectedFindingIds, $context->expectedFindingGroups, $context->unreachablePaths);
        $home = $runner->prepare($claimed, $run, $slot, $next, $dispatched->workspace);
        self::assertFalse($runner->dispatched($claimed, $home));
        $before = file_get_contents(dirname($home->root).'/binding.json');
        file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', json_encode([
            'boot_id' => str_repeat('a', 32), 'recorded_at' => time() - 60,
        ], JSON_THROW_ON_ERROR));
        try {
            $runner->prepare($claimed, $run, $slot, $next, $dispatched->workspace);
            self::fail('An undispatched home must wait for fresh presence.');
        } catch (AgentCapabilityPending) {
            self::assertSame($before, file_get_contents(dirname($home->root).'/binding.json'));
        }
        config(['ai6.runtime_role' => 'agent']);
        app(ProviderCapabilityPublisher::class)->pulse(str_repeat('a', 32));
        config(['ai6.runtime_role' => 'worker']);
        $resumed = $runner->prepare($claimed, $run, $slot, $next, $dispatched->workspace);
        self::assertDirectoryExists($resumed->root);
        self::assertSame(realpath($home->root), realpath($resumed->root));
        self::assertSame($before, file_get_contents(dirname($resumed->root).'/binding.json'));
        $runner->destroy($home);
        $runner->destroy($dispatched);
    }

    /** @return list<array{string}> */
    public static function stagingPresenceChanges(): array
    {
        return [['restart'], ['expired_presence'], ['legacy_boot']];
    }

    #[DataProvider('stagingPresenceChanges')]
    public function test_pending_staging_survives_presence_expiry_and_agent_restart(string $change): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-STAGING-PRESENCE');
        $this->expireCodexReport();
        $run = $prepared['run'];
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        $dispatch = fn () => (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), app(RunImplementation::class));
        $dispatch();
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        $runner = app(AgentExecutionRunner::class);
        $intent = $runner->intent($job);
        $generationKey = array_find(array_keys($intent), static fn (string $key): bool => str_ends_with($key, '_generation'));
        self::assertNotNull($generationKey);
        $bootKey = substr($generationKey, 0, -strlen('_generation')).'_boot';
        self::assertArrayNotHasKey($bootKey, $intent);
        if ($change === 'legacy_boot') {
            $job->forceFill(['intent' => json_encode([...$intent, $bootKey => str_repeat('a', 32)], JSON_THROW_ON_ERROR)])->save();
        }
        $document = app(ProviderCapabilityReport::class)->read('codex_cli', false);
        self::assertNotNull($document);
        $boot = $change === 'expired_presence' ? $document['boot_id'] : bin2hex(random_bytes(16));
        file_put_contents($this->onboardingRoot.'/presence/boot-id', $boot);
        file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', json_encode([
            'boot_id' => $document['boot_id'], 'recorded_at' => time() - 60,
        ], JSON_THROW_ON_ERROR));
        // Refresh the report first: presence alone must keep staging pending.
        config(['ai6.runtime_role' => 'agent']);
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time(), $document['boot_id']));
        config(['ai6.runtime_role' => 'worker']);
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job->fresh()));
        $dispatch();
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        self::assertSame($intent, $runner->intent($job));
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
        config(['ai6.runtime_role' => 'agent']);
        app(ProviderCapabilityPublisher::class)->pulse($boot);
        config(['ai6.runtime_role' => 'worker']);
        if ($boot !== $document['boot_id']) {
            // Current presence with the preceding boot's report still waits.
            self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
            $dispatch();
            self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
            self::assertSame($intent, $runner->intent($job));
            self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
        }
        config(['ai6.runtime_role' => 'agent']);
        $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time(), $boot));
        config(['ai6.runtime_role' => 'worker']);
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
        $dispatch();
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(0, $job->refresh()->attempts);
        self::assertCount(1, glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
        foreach ($intent as $key => $value) {
            self::assertSame($value, $runner->intent($job)[$key]);
        }
        self::assertArrayNotHasKey($bootKey, $runner->intent($job));
    }

    /** @return list<array{string}> */
    public static function stagingWaitFailures(): array
    {
        return [['revocation'], ['channel'], ['deadline'], ['missing_presence'], ['invalid_presence']];
    }

    #[DataProvider('stagingWaitFailures')]
    public function test_staging_wait_remains_bounded_by_revocation_channel_and_deadline(string $failure): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-STAGING-FAILURE');
        $this->expireCodexReport();
        $run = $prepared['run'];
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        $dispatch = fn () => (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), app(RunImplementation::class));
        $dispatch();
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        if ($failure === 'channel') {
            unlink($this->onboardingRoot.'/reports/codex_cli.json');
        } elseif ($failure === 'revocation') {
            config(['ai6.runtime_role' => 'agent']);
            app(ProviderCredentialStore::class)->replace('codex_cli', null);
            config(['ai6.runtime_role' => 'worker']);
        } elseif ($failure === 'missing_presence') {
            unlink($this->onboardingRoot.'/presence/heartbeat.json');
        } elseif ($failure === 'invalid_presence') {
            file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', '{}');
        } else {
            $intent = app(AgentExecutionRunner::class)->intent($job);
            foreach ($intent as $key => $binding) {
                if (str_starts_with($key, 'agent_staging_') && str_ends_with($key, '_deadline')) {
                    $intent[$key] = time() - 1;
                }
            }
            $job->forceFill(['intent' => json_encode($intent, JSON_THROW_ON_ERROR)])->save();
        }
        if (in_array($failure, ['revocation', 'deadline'], true)) {
            file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', json_encode([
                'boot_id' => str_repeat('a', 32), 'recorded_at' => time() - 60,
            ], JSON_THROW_ON_ERROR));
        }
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
        $dispatch();
        self::assertSame(RunState::FAILED, $run->fresh()->state);
        self::assertSame(match ($failure) {
            'revocation' => 'agent_credential_revision_changed',
            'channel', 'missing_presence', 'invalid_presence' => 'agent_capability_channel_unavailable',
            default => 'agent_execution_deadline_exceeded',
        }, $job->refresh()->failure_code);
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
    }

    public function test_expired_evidence_at_claim_runs_one_native_probe_before_starting(): void
    {
        $prepared = $this->preparedCodexRun('AI6-035-CLAIM-RECHECK');
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $this->expireCodexReport();
        self::assertTrue(NativeProviderMailbox::processNext());
        self::assertSame(1, NativeProviderMailbox::$capabilityProbes);
        self::assertNotNull(app(ProviderCapabilityReport::class)->read('codex_cli'));
        self::assertNotNull(app(AgentExecutionRunner::class)->dispatchOrCollect($run, $this->claim($job), $home, $context));
        app(AgentExecutionRunner::class)->destroy($home);
    }

    public function test_a_fresh_negative_recheck_at_claim_never_starts_the_provider(): void
    {
        $this->nativeMailbox = false;
        $prepared = $this->preparedCodexRun('AI6-035-CLAIM-NEGATIVE');
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $document = app(ProviderCapabilityReport::class)->read('codex_cli');
        self::assertNotNull($document);
        $rows = array_map(static fn (array $row): array => [...$row, 'status' => 'degraded', 'reason' => 'probe'], $document['rows']);
        config(['ai6.runtime_role' => 'agent']);
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $rows, time(), $document['boot_id']));
        $adapter = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        self::assertTrue(app(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertSame(0, $adapter->turnCount);
        config(['ai6.runtime_role' => 'worker']);
        try {
            app(AgentExecutionRunner::class)->dispatchOrCollect($run, $this->claim($job), $home, $context);
            self::fail('A negative recheck must not release the turn.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_selection_not_allowed', $exception->reason);
        } finally {
            app(AgentExecutionRunner::class)->destroy($home);
        }
    }

    private function expireCodexReport(): void
    {
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('codex_cli');
        self::assertNotNull($document);
        $role = config('ai6.runtime_role');
        config(['ai6.runtime_role' => 'agent']);
        try {
            $store = app(ProviderCredentialStore::class);
            $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time() - 301, $document['boot_id']));
        } finally {
            config(['ai6.runtime_role' => $role]);
        }
    }

    /** TC-07 over the seam: a codex reviewer slot runs read-only next to the fake slot with its own session and home. */
    public function test_a_review_turn_runs_read_only_with_its_own_session_and_reports_usage(): void
    {
        $this->configureCodex('success');
        $prepared = $this->preparedReviewRun('AI6-033-TC07-REVIEW');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::SUCCESS]);
        $this->bindAliasAwareAdapters();

        $job = $this->executeCodexReview($prepared['run']);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, json_encode(ReviewResult::query()
            ->where('run_id', $prepared['run']->id)->get(['slot_id', 'invocation_outcome', 'failure_code'])->toArray(), JSON_UNESCAPED_SLASHES));
        $results = ReviewResult::query()->where('run_id', $prepared['run']->id)->orderBy('id')->get();
        self::assertCount(2, $results);
        self::assertSame([ReviewInvocationOutcome::VALID_RESULT], $results->pluck('invocation_outcome')->unique()->values()->all());
        self::assertCount(2, $results->pluck('session_id')->unique());
        $codexSlot = RunAgent::query()->where('run_id', $prepared['run']->id)->where('provider_profile', 'codex_cli')->sole();
        self::assertSame($this->reviewSlotIds[1], $codexSlot->slot_id);
        $command = $this->app->make(CodexCliAdapter::class)->lastCommand;
        self::assertSame('read-only', $this->optionValue($command, '--sandbox'));
        self::assertSame($codexSlot->model, $this->optionValue($command, '--model'));
        $observation = $this->lastObservation;
        self::assertIsArray($observation);
        self::assertSame('denied', $observation['review_write']['existing'] ?? null, 'The review turn cannot write the sealed workspace.');
        self::assertSame(['auth.json'], $observation['codex_home_entries']);
        $codexAnswer = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')
            ->where('redacted_metadata->slot_id', $codexSlot->slot_id)->sole();
        self::assertSame(CodexCliAdapter::USAGE_SOURCE, $codexAnswer->redacted_metadata['usage_source']);
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /** TC-09 and TC-10 over the seam: hull and process failures reach the stored step result as invalid_json or provider_error. */
    #[DataProvider('failureScenarios')]
    public function test_failures_reach_the_stored_step_result_as_invalid_json_or_provider_error(string $scenario, WaitReason $expected): void
    {
        Mail::fake();
        if ($scenario === 'timeout') {
            config(['ai6.process.policies.agent.timeout_seconds' => 2]);
        }
        $prepared = $this->preparedCodexRun('AI6-033-TC09-'.strtoupper(str_replace('_', '-', $scenario)), $scenario);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');

        $job = $this->executeCodexImplement($prepared['run']);

        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        self::assertSame(RunState::WAITING, $prepared['run']->fresh()->state);
        self::assertSame($expected, $prepared['run']->fresh()->wait_reason, $scenario);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'), 'No partial import.');
        $answers = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->get();
        foreach ($answers as $answer) {
            $state = $answer->redacted_metadata['validation_state'] ?? $answer->redacted_metadata['state'];
            self::assertSame($expected === WaitReason::INVALID_JSON ? 'invalid_json' : 'provider_error', $state, $scenario);
            self::assertStringNotContainsString('fake codex', json_encode($answer->redacted_metadata, JSON_UNESCAPED_SLASHES), 'No provider text in the result.');
        }
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /**
     * TC-09 and TC-10 over the seam: a turn that reached turn.completed and
     * reported usage keeps those values as provider-artifact metadata even
     * when its answer contract broke afterwards. The failure state and the
     * import block stay exactly as they are; only the usage survives.
     */
    #[DataProvider('reportedUsageFailureScenarios')]
    public function test_reported_usage_survives_a_broken_answer_contract(string $scenario, string $reason): void
    {
        Mail::fake();
        $prepared = $this->preparedCodexRun('AI6-033-TC10-'.strtoupper(str_replace('_', '-', $scenario)), $scenario);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');

        $job = $this->executeCodexImplement($prepared['run']);

        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        self::assertSame(WaitReason::INVALID_JSON, $prepared['run']->fresh()->wait_reason, $scenario);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'), 'No partial import.');
        $answers = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->get();
        self::assertNotEmpty($answers, $scenario);
        foreach ($answers as $answer) {
            self::assertSame('invalid_json', $answer->redacted_metadata['validation_state'] ?? $answer->redacted_metadata['state'], $scenario);
            self::assertSame(['input_tokens' => 120, 'cached_input_tokens' => 20, 'output_tokens' => 45], $answer->redacted_metadata['usage'], $scenario);
            self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->redacted_metadata['usage_source'], $scenario);
        }
        self::assertTrue(RunEvent::query()->where('run_id', $prepared['run']->id)
            ->where('redacted_payload', 'like', $reason.'%')->exists(), $scenario.': the named failure stays visible.');
    }

    /** @return array<string, array{string, string}> */
    public static function reportedUsageFailureScenarios(): array
    {
        return [
            'missing answer' => ['missing_answer', 'agent_response_missing'],
            'two result answers in one turn' => ['double_answer', 'agent_response_multiple'],
            'answer after the turn completed' => ['answer_after_turn', 'agent_response_after_turn'],
        ];
    }

    /** @return array<string, array{string, WaitReason}> */
    public static function failureScenarios(): array
    {
        return [
            'missing answer' => ['missing_answer', WaitReason::INVALID_JSON],
            'two result answers in one turn' => ['double_answer', WaitReason::INVALID_JSON],
            'answer after the turn completed' => ['answer_after_turn', WaitReason::INVALID_JSON],
            'syntactically invalid answer' => ['invalid_answer', WaitReason::INVALID_JSON],
            'foreign schema' => ['foreign_schema', WaitReason::INVALID_JSON],
            'invalid utf8' => ['invalid_utf8', WaitReason::INVALID_JSON],
            'exit failure' => ['exit_failure', WaitReason::PROVIDER_ERROR],
            'turn failed' => ['turn_failed', WaitReason::PROVIDER_ERROR],
            'timeout' => ['timeout', WaitReason::PROVIDER_ERROR],
        ];
    }

    /** TC-10 over the seam: a reported zero stays zero and an absent usage stays unknown at the stored artifact. */
    public function test_reported_zero_and_absent_usage_are_stored_as_reported(): void
    {
        Mail::fake();
        foreach (['usage_zero' => [['input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0], CodexCliAdapter::USAGE_SOURCE], 'usage_missing' => [[], 'unknown']] as $scenario => [$usage, $source]) {
            $prepared = $this->preparedCodexRun('AI6-033-TC10-'.strtoupper(str_replace('_', '-', $scenario)), $scenario);
            $job = $this->executeCodexImplement($prepared['run']);
            self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
            $answer = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->sole();
            self::assertSame($usage, $answer->redacted_metadata['usage'], $scenario);
            self::assertSame($source, $answer->redacted_metadata['usage_source'], $scenario);
        }
    }

    /** TC-12 and TC-13: a missing projection ends by name before any start, and the homes are cleaned. */
    public function test_a_missing_credential_projection_ends_named_before_any_start(): void
    {
        Mail::fake();
        $prepared = $this->preparedCodexRun('AI6-033-TC12-MISSING');
        $job = $this->executeCodexImplement($prepared['run'], projectAuth: false);

        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        self::assertSame(WaitReason::PROVIDER_ERROR, $prepared['run']->fresh()->wait_reason);
        self::assertSame([], $this->app->make(CodexCliAdapter::class)->lastCommand, 'No Codex process started.');
        self::assertTrue(RunEvent::query()->where('run_id', $prepared['run']->id)
            ->where('redacted_payload', 'like', 'agent_codex_credential_projection_missing%')->exists());
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /** TC-08 and TC-12: credential rotation and runtime drift between staging and claim prevent the start and fence the result. */
    #[DataProvider('driftCases')]
    public function test_binding_drift_between_staging_and_claim_prevents_the_start(string $case, string $reason): void
    {
        $prepared = $this->preparedCodexRun('AI6-033-TC12-'.strtoupper($case));
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $this->projectTestAuth();
        if ($case === 'credential') {
            config(['ai6.runtime_role' => 'agent']);
            app(ProviderCredentialStore::class)->replace('codex_cli', null);
            config(['ai6.runtime_role' => 'worker']);
        } else {
            $profiles = config('ai6.provider_runtime_profiles');
            $profiles['codex-cli-v1']['version']++;
            config(['ai6.provider_runtime_profiles' => $profiles]);
            $this->app->forgetInstance(ProviderRuntimeProfileRegistry::class);
        }
        $this->app->forgetInstance(AgentExecutionProcessor::class);
        $this->app->forgetInstance(AgentExecutionRunner::class);

        config(['ai6.runtime_role' => 'agent']);
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        config(['ai6.runtime_role' => 'worker']);
        self::assertSame([], $this->app->make(CodexCliAdapter::class)->lastCommand, 'The agent consumer refused the drifted binding before the adapter.');
        self::assertNull(FakeCodexBinary::observation($home->resultDirectory));
        $runner = $this->app->make(AgentExecutionRunner::class);
        try {
            $runner->dispatchOrCollect($run, $this->claim($job), $home, $context);
            self::fail('The drifted execution was collected.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        } finally {
            $runner->destroy($home);
        }
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
        // Only the role-wide mailbox infrastructure remains without a drained supervisor.
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*'));
        self::assertSame([], glob(AgentExecutionProcessor::outputRoot().'/execution-*'));
        foreach (['claims', 'heartbeats', 'results', 'consumed'] as $directory) {
            self::assertSame([], glob(AgentExecutionProcessor::outputRoot().'/'.$directory.'/*'));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function driftCases(): array
    {
        return [
            'credential rotation or logout' => ['credential', 'agent_credential_revision_changed'],
            'runtime profile drift' => ['runtime', 'agent_runtime_profile_changed'],
        ];
    }

    /** TC-06 over the seam: a slot whose approved selection left the allowlist is refused before staging. */
    public function test_a_selection_outside_the_allowlist_is_refused_before_staging(): void
    {
        $prepared = $this->preparedCodexRun('AI6-033-TC06-SELECTION');
        [$run, $job, , $home, $context] = $this->stage($prepared['run']);
        $runner = $this->app->make(AgentExecutionRunner::class);
        $runner->destroy($home);
        $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->sole();
        $slot->forceFill(['effort' => 'ultra'])->save();
        try {
            $runner->prepare($this->claim($job), $run, $slot->fresh(), $context, $prepared['worktree']);
            self::fail('A free effort must not be staged.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_selection_not_allowed', $exception->reason);
        }
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
    }

    /** @var array<string, mixed>|null */
    private ?array $lastObservation = null;

    #[DataProvider('withdrawalPhases')]
    public function test_a_booted_worker_refuses_start_and_resume_after_report_withdrawal(bool $staged): void
    {
        Mail::fake();
        $prepared = $this->preparedCodexRun('AI6-035-WITHDRAW-'.($staged ? 'RESUME' : 'START'));
        $run = $prepared['run'];
        $runner = app(AgentExecutionRunner::class);
        $orchestrator = app(RunOrchestrator::class);
        if ($staged) {
            [, $job] = $this->stage($run);
            self::assertTrue($orchestrator->resumeStep($job));
        } else {
            $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        }
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $before = $approval->getAttributes();
        $original = file_get_contents($prepared['worktree'].'/app/Example.php');
        unlink($this->onboardingRoot.'/reports/codex_cli.json');
        (new ExecuteRunStep($job->id))->handle($orchestrator, app(RunImplementation::class));
        self::assertSame($runner, app(AgentExecutionRunner::class));
        self::assertSame(ExecutionJobState::FAILED, $job->refresh()->state);
        self::assertSame('agent_selection_not_allowed', $job->failure_code);
        self::assertSame(RunState::FAILED, $run->refresh()->state);
        self::assertSame([], app(CodexCliAdapter::class)->lastCommand);
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->where('kind', 'provider_raw')->count());
        self::assertSame($original, file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame($before, $approval->refresh()->getAttributes());
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json'));
    }

    /** @return array<string, array{bool}> */
    public static function withdrawalPhases(): array
    {
        return ['first start' => [false], 'resume staged turn' => [true]];
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        $this->seedProviderReports();
        $profiles = $this->app->make(AgentProfileRegistry::class);
        $this->reviewSlotIds = [(string) Str::uuid(), (string) Str::uuid()];
        $limits = ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class));
        if ($this->codexImplementer) {
            return new ApprovalSelection(
                $profiles->resolve('codex-gpt-5.6-terra', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'),
                $this->app->make(ReviewerSlotFactory::class)->fromArray([
                    ['id' => $this->reviewSlotIds[0], 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'high', 'prompt_profile' => 'security'],
                ]),
                $limits,
                $attentionUser?->getKey(),
                'manual',
            );
        }

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([
                ['id' => $this->reviewSlotIds[0], 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'high', 'prompt_profile' => 'security'],
                ['id' => $this->reviewSlotIds[1], 'profile' => 'codex-gpt-5.6-terra', 'model' => 'gpt-5.3-codex', 'effort' => 'high', 'prompt_profile' => 'tests'],
            ]),
            $limits,
            $attentionUser?->getKey(),
            'manual',
        );
    }

    private function configureCodex(string $scenario): string
    {
        $binary = FakeCodexBinary::create($this->wrappers, $scenario);
        $profiles = config('ai6.agent_profiles');
        $profiles['codex-gpt-5.6-terra']['capability_status'] = 'available';
        config([
            'ai6.agent_profiles' => $profiles,
            'ai6.credential_revisions.codex_cli' => 'test-v1',
            'ai6.codex.binary' => $binary,
            'ai6.codex.pinned_version' => FakeCodexBinary::PINNED_VERSION,
            'ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof(),
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
        ]);
        foreach ([
            AgentProfileRegistry::class, ReviewerSlotFactory::class, EffectiveProjectConfiguration::class, ApprovalSnapshotFactory::class,
            CredentialRevisionRegistry::class, CodexCliConfiguration::class, CodexCliAdapter::class, ExecutionHomeManager::class,
            InstructionBindingVerifier::class, AgentExecutionRunner::class, AgentExecutionProcessor::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }

        return $binary;
    }

    /** @return array{run: Run, project: mixed, operator: User, worktree: string, isolatedRoot: string} */
    private function preparedCodexRun(string $ticketId, string $scenario = 'success'): array
    {
        $this->configureCodex($scenario);
        $this->codexImplementer = true;
        $prepared = $this->preparedImplementationRun($ticketId);
        $this->bindAliasAwareAdapters();

        return $prepared;
    }

    /**
     * The fixture binds every alias to the FakeAgent on Windows; the seam under
     * test needs the alias-aware resolution the shipped provider performs
     * (proven separately by test_the_container_binding_resolves_...).
     */
    private function bindAliasAwareAdapters(): void
    {
        $this->app->bind(AgentAdapter::class, fn ($app, array $parameters): AgentAdapter => match ($parameters['providerAlias'] ?? 'fake') {
            'fake' => $this->app->make(FakeAgentAdapter::class),
            CodexCliAdapter::PROVIDER_ALIAS => $this->app->make(CodexCliAdapter::class),
            default => throw new AgentExecutionException('agent_adapter_unavailable'),
        });
    }

    private function executeCodexImplement(Run $run, bool $projectAuth = true): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
        AgentMailboxFixture::drain($job, function () use ($job): void {
            $this->captureObservation();
            (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
        }, function () use ($projectAuth): void {
            $this->projectTestAuth();
            $auth = $this->onboardingRoot.'/store/codex_cli/auth.json';
            if (! $projectAuth && is_file($auth)) {
                unlink($auth);
            }
        });

        return $job->fresh() ?? $job;
    }

    private function executeCodexReview(Run $run): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', ExecutionStepType::REVIEW->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), reviews: $this->app->make(ReviewRound::class));
        AgentMailboxFixture::drain($job, function () use ($job): void {
            $this->captureObservation();
            (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), reviews: $this->app->make(ReviewRound::class));
        }, $this->projectTestAuth(...));

        return $job->fresh() ?? $job;
    }

    /** The worker's staged home stays empty; only the agent owns the store. */
    private function projectTestAuth(): void
    {
        foreach (glob(AgentExecutionProcessor::inputRoot().'/execution-*/*/home/auth', GLOB_ONLYDIR) ?: [] as $directory) {
            self::assertSame(['.', '..'], scandir($directory));
        }
    }

    /** Remember the fake's observation after the agent consumer ran and before the worker collects and destroys the home. */
    private function captureObservation(): void
    {
        foreach (glob(AgentExecutionProcessor::outputRoot().'/execution-*/*/result', GLOB_ONLYDIR) ?: [] as $result) {
            $observation = FakeCodexBinary::observation($result);
            if ($observation !== null) {
                $this->lastObservation = $observation;
            }
        }
    }

    /** @return array{Run, ExecutionJob, AgentExecutionRequest, ExecutionHome, AgentResultContext} */
    private function stage(Run $run): array
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        $bindings = glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json');
        self::assertIsArray($bindings);
        self::assertCount(1, $bindings);
        $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes($bindings[0]));
        self::assertSame('codex_cli', $request->string('provider_alias'));
        self::assertSame('gpt-5.3-codex', $request->string('model'));
        self::assertSame('medium', $request->string('effort'));
        $home = AgentExecutionProcessor::home($request);
        $context = AgentResultContext::fromJson(AgentExecutionProcessor::readBytes(dirname($home->runtimeConfiguration).'/turn.json'),
            $this->app->make(ProviderRuntimeProfileRegistry::class), $request->string('context_hash'));
        self::assertSame('gpt-5.3-codex', $context->model);
        self::assertSame('medium', $context->effort);

        return [$run, $job, $request, $home, $context];
    }

    private function claim(ExecutionJob $job): ExecutionJob
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        self::assertTrue($orchestrator->resumeStep($job));
        $claimed = $orchestrator->claimStep($job->fresh(), 'codex-test');
        self::assertInstanceOf(ExecutionJob::class, $claimed);

        return $claimed;
    }

    /** @param list<string> $command */
    private function optionValue(array $command, string $name): ?string
    {
        $index = array_search($name, $command, true);

        return $index === false ? null : $command[$index + 1];
    }
}
