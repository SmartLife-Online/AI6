<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentExecutionRequest;
use App\AI6\Agents\AgentExecutionRunner;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\AgentTurnResult;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InvalidAgentResponse;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class AgentExecutionMailboxTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_polls_preserve_attempt_and_identity_and_the_database_free_consumer_runs_once(): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $attempt = $job->attempts;
        self::assertSame(0, $attempt);
        $intent = $job->intent;
        for ($poll = 0; $poll < 2; $poll++) {
            self::assertTrue($this->app->make(RunOrchestrator::class)->resumeStep($job));
            (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
            $job->refresh();
            self::assertSame($attempt, $job->attempts);
            self::assertSame($intent, $job->intent);
            self::assertSame(ExecutionJobState::WAITING, $job->state);
        }
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $processor = $this->app->make(AgentExecutionProcessor::class);
        self::assertTrue($processor->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertFalse($processor->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertSame(0, $queries);
        self::assertSame(1, $this->app->make(FakeAgentAdapter::class)->turnCount);
        $claimed = $this->claim($job);
        $runner = $this->app->make(AgentExecutionRunner::class);
        $answer = $runner->dispatchOrCollect($run, $claimed, $home, $context);
        self::assertInstanceOf(AgentTurnResult::class, $answer);
        self::assertSame($answer->bytes, $runner->dispatchOrCollect($run, $claimed, $home, $context)?->bytes);
        $artifact = $runner->store($run, RunArtifactKind::PROVIDER_RAW, $answer->bytes, [],
            new RedactionContext('test', $run->id, 'answer'));
        self::assertSame($artifact->id, $runner->store($run, RunArtifactKind::PROVIDER_RAW, $answer->bytes, [],
            new RedactionContext('test', $run->id, 'answer'))->id);
        self::assertSame($request->string('execution_id'), $artifact->execution_id);
        self::assertSame('unknown', $artifact->redacted_metadata['usage_source']);
        $runner->destroy($home);
        $slot = $run->agents()->where('slot_id', $request->string('slot_id'))->sole();
        $this->expectException(AgentExecutionException::class);
        $this->expectExceptionMessage('agent_execution_already_terminal');
        $runner->prepare($claimed, $run, $slot, $context, $run->worktree_path);
    }

    /**
     * Once dispatched, the writable workspace belongs to the concurrently
     * running turn: a tree-wide re-check on a later poll must not race a file
     * the provider is creating or removing (POSIX symlink evidence).
     */
    public function test_a_dispatched_step_does_not_re_walk_the_writable_workspace_on_the_next_poll(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The writable-workspace race guard is POSIX-only evidence.');
        }
        [$run, $job, , $home] = $this->stage();
        self::assertTrue($this->app->make(AgentExecutionRunner::class)->dispatched($job, $home));
        $link = $home->workspace.'/racy-link';
        self::assertTrue(symlink($home->workspace.'/missing-target', $link));
        try {
            self::assertTrue($this->app->make(RunOrchestrator::class)->resumeStep($job));
            (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
            $job->refresh();
            self::assertSame(ExecutionJobState::WAITING, $job->state);
            self::assertNull($job->failure_code);
            self::assertContains($run->fresh()->state->value, ['queued', 'running'], 'The racy symlink must not fail the run.');
        } finally {
            unlink($link);
        }
    }

    /**
     * AI6-047 finding: the "already consumed" guard in prepare() used to
     * recognize only a stored PROVIDER_RAW artifact, but an implementation or
     * fix turn's importChanges() durably mutates the run's worktree before
     * that artifact is ever written (RunImplementation::handleResult() calls
     * markImported() right after the import succeeds, persistOutcome() only
     * later). A RunTransitionConflict from bindActualChangedPaths() — or a
     * crash in that same window — can therefore leave an import applied with
     * no artifact bound to it at all. This proves the import marker alone,
     * without any RunArtifact ever existing, still makes a redelivered
     * prepare() for the same identity fail closed instead of silently
     * re-running the whole turn and risking a second, divergent import.
     */
    public function test_an_import_without_a_stored_artifact_still_blocks_a_redelivered_prepare(): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $processor = $this->app->make(AgentExecutionProcessor::class);
        self::assertTrue($processor->processNext(str_repeat('a', 32), static function (): void {}));
        $claimed = $this->claim($job);
        $runner = $this->app->make(AgentExecutionRunner::class);
        self::assertInstanceOf(AgentTurnResult::class, $runner->dispatchOrCollect($run, $claimed, $home, $context));

        $slot = $run->agents()->where('slot_id', $request->string('slot_id'))->sole();
        $runner->markImported($claimed, $slot, $context);
        self::assertSame(
            0,
            RunArtifact::query()->where('run_id', $run->id)->where('kind', RunArtifactKind::PROVIDER_RAW->value)->count(),
            'No artifact must exist for this proof — the import marker alone must already be enough.',
        );

        $runner->destroy($home);
        $this->expectException(AgentExecutionException::class);
        $this->expectExceptionMessage('agent_execution_already_terminal');
        $runner->prepare($claimed, $run, $slot, $context, $run->worktree_path);
    }

    /**
     * AI6-047 finding: dispatched() used to accept only $job and treat any
     * 'agent_dispatched_*' intent key as proof the current execution was
     * already handed off — so a second, freshly prepared attempt under a
     * different execution identity (a new attempt number, after the first
     * attempt's home was destroyed) would inherit the first attempt's
     * "already dispatched" state and skip assertWorkspaceProjection()
     * (RunImplementation.php:228-234) before it was ever actually dispatched
     * itself. The fix reads the specific home's own execution_id from its
     * binding.json and checks only that key. This proves the fixed contract
     * directly at its own boundary: a stale key from one execution must not
     * make dispatched() true for a different, freshly prepared one — the
     * one fact RunImplementation's pre-turn guard (line 228) relies on to
     * decide whether assertWorkspaceProjection() still has to run for it.
     */
    public function test_a_fresh_attempts_home_is_not_mistaken_for_an_earlier_attempts_dispatch(): void
    {
        [$run, $job, , $home, $context] = $this->stage();
        $runner = $this->app->make(AgentExecutionRunner::class);
        self::assertTrue($runner->dispatched($job, $home), "The first attempt's own execution must read as dispatched.");

        $slot = $run->agents()->where('slot_id', $context->slotId)->sole();
        $secondAttempt = new AgentResultContext(
            $context->role, $context->promptSnapshot, $context->instructionSnapshot, $context->runtimeProfile,
            $context->criterionRefs, $context->actualDiff, $context->instructionUpdate, $context->initialScope,
            $context->expectedInstructionBlobs, $context->slotId, $context->attempt + 1, $context->expectedFindingIds,
            $context->expectedFindingGroups, $context->unreachablePaths,
        );
        $export = $this->implementationTemp('fresh-attempt-export-'.bin2hex(random_bytes(3))).'/tree';
        (new IsolatedTreeExporter)->export($run->worktree_path, $export);
        $claimed = $this->claim($job);
        $freshHome = $runner->prepare($claimed, $run, $slot, $secondAttempt, $export);
        self::assertNotSame($home->root, $freshHome->root, 'The second attempt must derive a distinct execution identity.');
        self::assertFalse(
            $runner->dispatched($claimed, $freshHome),
            "The first attempt's dispatched key must not leak onto the freshly prepared home — otherwise "
            .'assertWorkspaceProjection() would be silently skipped for a turn that was never actually dispatched.',
        );
    }

    #[DataProvider('terminalCases')]
    public function test_late_or_foreign_results_cannot_create_an_artifact(string $case, string $reason): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $processor = $this->app->make(AgentExecutionProcessor::class);
        self::assertTrue($processor->processNext(str_repeat('a', 32), static function (): void {}));
        $claimed = $this->claim($job);
        if ($case === 'answer') {
            file_put_contents($home->resultDirectory.'/answer.txt', 'different answer');
        } elseif ($case === 'boot') {
            $processor->publishBoot(str_repeat('b', 32));
        } elseif ($case === 'cancel') {
            $this->app->make(RunOrchestrator::class)->failRun($run->id);
        } elseif ($case === 'runtime') {
            $profiles = config('ai6.provider_runtime_profiles');
            $profiles['fake-v1']['version']++;
            config(['ai6.provider_runtime_profiles' => $profiles]);
            $this->app->forgetInstance(ProviderRuntimeProfileRegistry::class);
        } elseif ($case === 'credential') {
            config(['ai6.credential_revisions.fake' => 'rotated-revision']);
            $this->app->forgetInstance(CredentialRevisionRegistry::class);
            $this->app->forgetInstance(AgentExecutionRunner::class);
        }
        try {
            $this->app->make(AgentExecutionRunner::class)->dispatchOrCollect($run, $claimed, $home, $context);
            self::fail('A fenced result was accepted.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        } finally {
            $this->app->make(AgentExecutionRunner::class)->destroy($home);
        }
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
        self::assertSame(1, $this->app->make(FakeAgentAdapter::class)->turnCount);
    }

    /** @return array<string, array{string, string}> */
    public static function terminalCases(): array
    {
        return [
            'answer hash' => ['answer', 'agent_result_answer_hash_invalid'],
            'new boot' => ['boot', 'agent_result_boot_invalid'],
            'terminal run' => ['cancel', 'agent_result_after_cancel'],
            'runtime rotation' => ['runtime', 'agent_runtime_profile_changed'],
            'credential rotation' => ['credential', 'agent_credential_revision_changed'],
        ];
    }

    public function test_a_claim_without_a_live_heartbeat_is_fenced_and_cleaned_without_an_adapter_restart(): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)
            ->forRole(ExecutionRole::AGENT);
        self::assertNotNull($mailbox->claimNext(MailboxMessageType::REQUEST,
            new RedactionContext('agent', null, 'crash-test')));
        $this->app->make(AgentExecutionProcessor::class)->publishBoot(str_repeat('a', 32));
        AgentExecutionProcessor::writeDocument(AgentExecutionProcessor::outputRoot().'/claims/'.$request->string('execution_id').'.json', [
            'schema' => 'ai6.agent-claim.v1', 'execution_id' => $request->string('execution_id'),
            'agent_boot_id' => str_repeat('a', 32), 'recorded_at' => time() - 3601,
        ]);
        $claimed = $this->claim($job);
        $runner = $this->app->make(AgentExecutionRunner::class);
        try {
            $runner->dispatchOrCollect($run, $claimed, $home, $context);
            self::fail('A dead claimed execution was accepted.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_execution_heartbeat_stale', $exception->reason);
        }
        self::assertFalse($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('b', 32), static function (): void {}));
        self::assertSame(0, $this->app->make(FakeAgentAdapter::class)->turnCount);
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
        $runner->destroy($home);
        self::assertDirectoryDoesNotExist(dirname($home->root));
        self::assertDirectoryDoesNotExist(dirname($home->outputRoot));
        foreach (['claims', 'heartbeats', 'results', 'consumed'] as $directory) {
            self::assertSame([], glob(AgentExecutionProcessor::outputRoot().'/'.$directory.'/*'));
        }
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/requests/*'));
    }

    public function test_terminal_worker_cleanup_revokes_an_outstanding_request(): void
    {
        [$run, $job, $request, $home] = $this->stage();
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class));
        self::assertDirectoryDoesNotExist(dirname($home->root));
        self::assertDirectoryDoesNotExist(dirname($home->outputRoot));
        self::assertFalse($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertSame(0, $this->app->make(FakeAgentAdapter::class)->turnCount);
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/requests/*'));
    }

    /** TC-10: a blocked cleanup is a visible timeline event, never a silently succeeding step. */
    public function test_a_blocked_terminal_cleanup_is_a_visible_timeline_event_and_not_silently_successful(): void
    {
        [$run, $job, $request, $home] = $this->stage();
        // A directory where the claim document would be unlinks() rather than
        // is absent: file_exists() sees it, unlink() on a directory fails on
        // every platform, so the blocked path is deterministic and portable.
        $blocked = AgentExecutionProcessor::outputRoot().'/claims/'.$request->string('execution_id').'.json';
        self::assertTrue(mkdir($blocked, 0700, true));
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        try {
            try {
                (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class));
                self::fail('A blocked cleanup must not complete silently.');
            } catch (AgentExecutionException $exception) {
                // unlink() on a directory raises E_WARNING before its own
                // return value is examined; AgentExecutionRunner's
                // tryFilesystemOperation() catches the resulting
                // \ErrorException so the named reason below is what actually
                // surfaces, on every platform.
                self::assertSame('agent_cleanup_failed', $exception->reason);
            }
            self::assertTrue(RunEvent::query()->where('run_id', $run->id)
                ->where('event_key', 'agent-cleanup:'.$job->id)
                ->where('redacted_payload', 'agent_home_cleanup_failed')
                ->exists());
        } finally {
            rmdir($blocked);
            try {
                $this->app->make(AgentExecutionRunner::class)->destroy($home);
            } catch (AgentExecutionException) {
                // Best-effort teardown once the blocking directory is gone;
                // the assertions above already proved the failure was visible.
            }
        }
    }

    public function test_changed_context_is_rejected_before_adapter_start_and_returns_a_typed_failure(): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $path = dirname($home->runtimeConfiguration).'/turn.json';
        chmod($path, 0660);
        file_put_contents($path, '{}');
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertSame(0, $this->app->make(FakeAgentAdapter::class)->turnCount);
        $claimed = $this->claim($job);
        try {
            $this->app->make(AgentExecutionRunner::class)->dispatchOrCollect($run, $claimed, $home, $context);
            self::fail('The changed context must be rejected.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_context_hash_invalid', $exception->reason);
        } finally {
            $this->app->make(AgentExecutionRunner::class)->destroy($home);
        }
    }

    public function test_unknown_alias_is_refused_by_the_single_container_binding(): void
    {
        self::assertInstanceOf(FakeAgentAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'fake']));
        $this->expectException(AgentExecutionException::class);
        $this->expectExceptionMessage('agent_adapter_unavailable');
        $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'not-implemented']);
    }

    #[DataProvider('answerCases')]
    public function test_answer_states_and_reported_usage_survive_the_mailbox(string $mode, string $state): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        $adapter = new class($mode) implements AgentAdapter
        {
            public function __construct(private string $mode) {}

            public function result(AgentResultContext $context): string
            {
                return '{}';
            }

            public function turn(AgentResultContext $context, ExecutionHome $home, \Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
            {
                $heartbeat();
                if ($this->mode === 'parser') {
                    throw new InvalidAgentResponse('agent_response_missing');
                }
                if ($this->mode === 'provider') {
                    throw new AgentExecutionException('agent_process_timeout');
                }

                return new AgentTurnResult($this->mode === 'encoding' ? "\xff" : '{}',
                    ['input_tokens' => 12, 'output_tokens' => null], 'provider_cli');
            }
        };
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        $runner = $this->app->make(AgentExecutionRunner::class);
        try {
            $answer = $runner->dispatchOrCollect($run, $this->claim($job), $home, $context);
            self::assertSame('ok', $state);
            self::assertInstanceOf(AgentTurnResult::class, $answer);
            $runner->store($run, RunArtifactKind::PROVIDER_RAW, $answer->bytes, [],
                new RedactionContext('test', $run->id, 'answer'));
        } catch (InvalidAgentResponse $exception) {
            self::assertSame('invalid_json', $state);
            self::assertSame($mode === 'encoding' ? 'agent_response_invalid_utf8' : 'agent_response_missing', $exception->reason);
        } catch (AgentExecutionException $exception) {
            self::assertSame('provider_error', $state);
            self::assertSame('agent_process_timeout', $exception->reason);
        } finally {
            $runner->destroy($home);
        }
        $artifact = RunArtifact::query()->where('run_id', $run->id)->where('kind', 'provider_raw')->sole();
        self::assertSame($request->string('execution_id'), $artifact->execution_id);
        self::assertSame($state, $artifact->redacted_metadata['state']);
        self::assertSame(str_repeat('a', 32), $artifact->redacted_metadata['agent_boot_id']);
        self::assertSame(in_array($mode, ['ok', 'encoding'], true) ? 'provider_cli' : 'unknown', $artifact->redacted_metadata['usage_source']);
        self::assertSame(in_array($mode, ['ok', 'encoding'], true) ? ['input_tokens' => 12, 'output_tokens' => null] : [], $artifact->redacted_metadata['usage']);
    }

    /** @return array<string, array{string, string}> */
    public static function answerCases(): array
    {
        return ['reported usage' => ['ok', 'ok'], 'invalid UTF-8' => ['encoding', 'invalid_json'],
            'missing answer' => ['parser', 'invalid_json'], 'timeout' => ['provider', 'provider_error']];
    }

    public function test_worker_revocation_during_a_turn_prevents_late_publication(): void
    {
        [$run, $job, $request, $home] = $this->stage();
        $runner = $this->app->make(AgentExecutionRunner::class);
        $adapter = new class($runner) implements AgentAdapter
        {
            public function __construct(private AgentExecutionRunner $runner) {}

            public function result(AgentResultContext $context): string
            {
                return '{}';
            }

            public function turn(AgentResultContext $context, ExecutionHome $home, \Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
            {
                $heartbeat();
                $this->runner->destroy($home);

                return new AgentTurnResult('{}');
            }
        };
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertDirectoryDoesNotExist(dirname($home->root));
        self::assertDirectoryDoesNotExist(dirname($home->outputRoot));
        foreach (['claims', 'heartbeats', 'results', 'consumed'] as $directory) {
            self::assertSame([], glob(AgentExecutionProcessor::outputRoot().'/'.$directory.'/*'));
        }
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
    }

    #[DataProvider('foreignResultFields')]
    public function test_a_result_cannot_rebind_any_frozen_request_field(string $field): void
    {
        [$run, $job, $request, $home, $context] = $this->stage();
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        $path = AgentExecutionProcessor::outputRoot().'/results/'.$request->deliveryId().'.json';
        $envelope = json_decode(AgentExecutionProcessor::readBytes($path), true, 32, JSON_THROW_ON_ERROR);
        $result = json_decode(base64_decode($envelope['content_base64'], true), true, 32, JSON_THROW_ON_ERROR);
        $result['request'][$field] = match ($field) {
            'execution_id', 'context_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash' => str_repeat('b', 64),
            'attempt', 'deadline_at' => $request->integer($field) + 1,
            'role' => 'quality_review',
            'home' => $request->string('home').'-foreign',
            default => 'foreign-bound-value',
        };
        if ($field === 'execution_id') {
            $result['request']['home'] = 'execution-'.str_repeat('b', 32).'/'.basename($home->root);
        }
        $this->replaceEnvelopeContent($path, json_encode($result, JSON_THROW_ON_ERROR));
        $runner = $this->app->make(AgentExecutionRunner::class);
        try {
            $runner->dispatchOrCollect($run, $this->claim($job), $home, $context);
            self::fail('A foreign result binding was accepted: '.$field);
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_result_binding_invalid', $exception->reason);
        } finally {
            $runner->destroy($home);
        }
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
    }

    /** @return array<string, array{string}> */
    public static function foreignResultFields(): array
    {
        $cases = [];
        foreach (['execution_id', 'run_id', 'slot_id', 'session_id', 'role', 'attempt', 'home', 'context_hash',
            'prompt_hash', 'instruction_hash', 'runtime_profile_id', 'runtime_profile_hash', 'provider_alias', 'credential_revision', 'deadline_at'] as $field) {
            $cases[$field] = [$field];
        }

        return $cases;
    }

    public function test_a_correct_result_received_after_its_frozen_deadline_has_no_effect(): void
    {
        [$run, $job, $original, $home, $context] = $this->stage();
        $request = new AgentExecutionRequest([...$original->fields, 'deadline_at' => time() + 3]);
        AgentExecutionProcessor::writeDocument(dirname($home->root).'/binding.json', $request->fields);
        $this->replaceEnvelopeContent(AgentExecutionProcessor::inputRoot().'/requests/'.$request->deliveryId().'.json', $request->toJson());
        self::assertTrue($this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {}));
        self::assertFileExists(AgentExecutionProcessor::outputRoot().'/results/'.$request->deliveryId().'.json');
        while (time() < $request->integer('deadline_at')) {
            usleep(10000);
        }
        $runner = $this->app->make(AgentExecutionRunner::class);
        $claimed = $this->claim($job);
        $before = $run->fresh()->getAttributes();
        try {
            $runner->dispatchOrCollect($run, $claimed, $home, $context);
            self::fail('An expired execution was accepted.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_execution_deadline_exceeded', $exception->reason);
        } finally {
            $runner->destroy($home);
        }
        self::assertSame($before, $run->fresh()->getAttributes());
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->count());
        self::assertDirectoryDoesNotExist(dirname($home->root));
        self::assertDirectoryDoesNotExist(dirname($home->outputRoot));
    }

    public function test_the_acknowledged_reduced_path_keeps_invalid_utf8_distinct_from_provider_errors(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-047-REDUCED');
        $strict = $this->app->make(SecurityPolicy::class);
        $measures = $strict->measures();
        $measures[SecurityMeasure::REQUIRE_AGENT_SANDBOX->value] = false;
        $reduced = new SecurityPolicy(SecurityProfile::CUSTOM, $measures, true);
        self::assertNotSame($strict->hash(), $reduced->hash());
        $this->app->instance(SecurityPolicy::class, $reduced);
        $this->app->forgetInstance(AgentExecutionRunner::class);
        $this->app->forgetInstance(RunImplementation::class);
        $adapter = new class implements AgentAdapter
        {
            public int $calls = 0;

            public function result(AgentResultContext $context): string
            {
                return "\xff";
            }

            public function turn(AgentResultContext $context, ExecutionHome $home, \Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
            {
                $heartbeat();
                $this->calls++;

                return new AgentTurnResult($this->result($context), ['tokens' => 4], 'provider_cli');
            }
        };
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::WAITING, $job->state);
        self::assertSame('invalid_json', $prepared['run']->fresh()->wait_reason?->value);
        self::assertSame(3, $adapter->calls);
        $answers = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->get();
        self::assertCount(3, $answers);
        foreach ($answers as $answer) {
            self::assertSame('invalid_json', $answer->redacted_metadata['state']);
            self::assertSame(['tokens' => 4], $answer->redacted_metadata['usage']);
            self::assertSame('provider_cli', $answer->redacted_metadata['usage_source']);
        }
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/requests/*'));
    }

    private function replaceEnvelopeContent(string $path, string $bytes): void
    {
        $envelope = json_decode(AgentExecutionProcessor::readBytes($path), true, 32, JSON_THROW_ON_ERROR);
        $envelope['content_base64'] = base64_encode($bytes);
        $envelope['content_sha256'] = hash('sha256', $bytes);
        $envelope['size'] = strlen($bytes);
        self::assertNotFalse(file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR)));
    }

    public function test_two_parallel_agent_consumers_start_one_turn_and_publish_one_result(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('The parallel agent consumer proof requires Linux and pcntl.');
        }
        [$run, $job, $request, $home, $context] = $this->stage();
        $root = AgentExecutionProcessor::outputRoot();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                while (! is_file($root.'/parallel-go')) {
                    usleep(1000);
                }
                try {
                    $this->app->make(AgentExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (): void {});
                    file_put_contents($root.'/parallel-'.$index, (string) $this->app->make(FakeAgentAdapter::class)->turnCount);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        file_put_contents($root.'/parallel-go', 'go');
        foreach ($children as $pid) {
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $counts = [file_get_contents($root.'/parallel-0'), file_get_contents($root.'/parallel-1')];
        sort($counts);
        self::assertSame(['0', '1'], $counts);
        self::assertCount(1, glob($root.'/results/*.json'));
        self::assertInstanceOf(AgentTurnResult::class, $this->app->make(AgentExecutionRunner::class)->dispatchOrCollect($run, $this->claim($job), $home, $context));
        $this->app->make(AgentExecutionRunner::class)->destroy($home);
        foreach (['parallel-go', 'parallel-0', 'parallel-1'] as $file) {
            self::assertTrue(unlink($root.'/'.$file));
        }
        self::assertSame([], glob($root.'/results/*'));
    }

    /** @return array{Run, ExecutionJob, AgentExecutionRequest, ExecutionHome, AgentResultContext} */
    private function stage(): array
    {
        $prepared = $this->preparedImplementationRun('AI6-047-MAILBOX', scenario: AgentScenario::SUCCESS);
        $run = $prepared['run'];
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'implement')->sole();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        self::assertSame(0, $this->app->make(FakeAgentAdapter::class)->turnCount);
        $bindings = glob(AgentExecutionProcessor::inputRoot().'/execution-*/binding.json');
        self::assertIsArray($bindings);
        self::assertCount(1, $bindings);
        $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes($bindings[0]));
        $home = AgentExecutionProcessor::home($request);
        $context = AgentResultContext::fromJson(AgentExecutionProcessor::readBytes(dirname($home->runtimeConfiguration).'/turn.json'),
            $this->app->make(ProviderRuntimeProfileRegistry::class), $request->string('context_hash'));

        return [$run, $job, $request, $home, $context];
    }

    private function claim(ExecutionJob $job): ExecutionJob
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        self::assertTrue($orchestrator->resumeStep($job));
        $claimed = $orchestrator->claimStep($job->fresh(), 'mailbox-test');
        self::assertInstanceOf(ExecutionJob::class, $claimed);

        return $claimed;
    }
}
