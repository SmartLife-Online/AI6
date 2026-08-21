<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckerExecutionProcessor;
use App\AI6\Checks\CheckExecutionBoundaryReached;
use App\AI6\Checks\CheckExecutionContractException;
use App\AI6\Checks\CheckExecutionRequest;
use App\AI6\Checks\CheckExecutionResultDocument;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\DuplicateCheckExecution;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Database\QueryException;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-01 result states, TC-06 mutation detection, TC-07 redaction and TC-08 phase binding
 * against real processes.
 */
final class CheckRunnerTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    public function test_the_reduced_test_path_has_a_distinct_policy_hash(): void
    {
        $strict = $this->app->make(SecurityPolicy::class);
        self::assertSame(SecurityProfile::STRICT, $strict->profile);
        self::assertTrue($strict->isEnabled(SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION));

        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $reduced = $this->app->make(SecurityPolicy::class);

        self::assertSame(SecurityProfile::CUSTOM, $reduced->profile);
        self::assertFalse($reduced->isEnabled(SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION));
        self::assertTrue($reduced->reducedModeAcknowledged);
        self::assertNotSame($strict->hash(), $reduced->hash());
    }

    /** TC-01: four executions, four distinguishable states. */
    public function test_success_failure_timeout_and_missing_tool_are_four_distinct_states(): void
    {
        $this->bindCheckRuntime([
            'probe-ok' => $this->probeProfile(['ai6-check-ok.php']),
            'probe-fail' => $this->probeProfile(['ai6-check-fail.php']),
            'probe-slow' => $this->probeProfile(['ai6-check-slow.php']),
            'probe-absent' => $this->probeProfile(['--version'], program: $this->missingProgram()),
        ], timeoutSeconds: 1);
        ['run' => $run] = $this->checkableRun('AI6-021-STATES');
        $runner = $this->app->make(CheckRunner::class);

        $ok = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');
        self::assertSame(CheckResultState::SUCCEEDED, $ok->state);
        self::assertSame(0, $ok->exit_code);
        self::assertNull($ok->reason);
        self::assertStringContainsString('check ok', $ok->redacted_output);

        $failed = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-fail');
        self::assertSame(CheckResultState::FAILED, $failed->state);
        self::assertSame(1, $failed->exit_code);
        self::assertSame('check_failed', $failed->reason);

        $absent = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-absent');
        self::assertSame(CheckResultState::TOOL_UNAVAILABLE, $absent->state);
        self::assertNotSame(CheckResultState::FAILED, $absent->state);
        self::assertNotNull($absent->reason);

        $slow = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-slow');
        self::assertSame(CheckResultState::TIMED_OUT, $slow->state);
        self::assertSame('process_timeout', $slow->reason);

        // No state was mapped onto another, and nothing unexecutable is green.
        $states = CheckResultRecord::query()->where('run_id', $run->id)->pluck('state')->all();
        self::assertCount(4, array_unique(array_map(static fn ($state): string => $state instanceof CheckResultState ? $state->value : (string) $state, $states)));
    }

    /** TC-06: a non-mutating declaration that changes the tree is a named failure. */
    public function test_an_undeclared_tree_mutation_ends_as_a_named_failure(): void
    {
        $this->bindCheckRuntime([
            'probe-mutate' => $this->probeProfile(['ai6-check-mutate.php'], mutates: false),
        ]);
        ['run' => $run] = $this->checkableRun('AI6-021-MUTATE');

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-mutate');

        self::assertSame(CheckResultState::FAILED, $record->state);
        self::assertSame('unexpected_tree_mutation', $record->reason);
        self::assertSame(0, $record->exit_code, 'The process itself succeeded; only the undeclared mutation failed it.');
        self::assertNotSame($record->tree_sha, $record->result_tree_sha);
        self::assertFalse($record->declared_mutates);
    }

    /** TC-06: a declared mutation stays visible and bound in the result. */
    public function test_a_declared_mutation_is_visible_and_bound_in_the_result(): void
    {
        $this->bindCheckRuntime([
            'probe-mutate' => $this->probeProfile(['ai6-check-mutate.php'], mutates: true),
        ]);
        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-MUTATE-OK');

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-mutate');

        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
        self::assertTrue($record->declared_mutates);
        self::assertNotSame($record->tree_sha, $record->result_tree_sha, 'The change is bound before and after the run.');
        // The review state itself never moved: the check ran on an export.
        self::assertFileDoesNotExist($worktree.'/ai6-check-mutation.txt');
    }

    /** TC-06: an unchanged tree keeps both bindings identical, so the detection can fail. */
    public function test_an_unchanged_tree_produces_an_identical_binding(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-021-STABLE');

        $runner = $this->app->make(CheckRunner::class);
        $record = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');

        self::assertSame($record->tree_sha, $record->result_tree_sha);
        self::assertSame($record->tree_sha, $runner->currentTreeBinding($run));
        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
    }

    /** TC-08: a profile outside its declared phase is refused before anything runs. */
    public function test_a_profile_outside_its_declared_phase_is_refused(): void
    {
        $this->bindCheckRuntime([
            'probe-ok' => $this->probeProfile(['ai6-check-ok.php'], phases: ['final']),
        ]);
        ['run' => $run] = $this->checkableRun('AI6-021-PHASE');

        try {
            $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');
            self::fail('The profile ran outside its declared phase.');
        } catch (CheckProfileConfigurationException $exception) {
            self::assertSame('check_profile_phase_not_allowed', $exception->reason);
        }

        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertSame(CheckResultState::SUCCEEDED, $this->app->make(CheckRunner::class)
            ->run($run, CheckPhase::FINAL, 'probe-ok')->state);
    }

    /** AC-05: a declared network need fails closed instead of running without one. */
    public function test_a_profile_declaring_a_network_need_fails_closed(): void
    {
        $profile = $this->probeProfile(['ai6-check-ok.php']);
        $profile['network'] = true;
        $this->bindCheckRuntime(['probe-net' => $profile]);
        ['run' => $run] = $this->checkableRun('AI6-021-NET');

        try {
            $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-net');
            self::fail('A profile declaring a network need was executed.');
        } catch (CheckProfileConfigurationException $exception) {
            self::assertSame('check_profile_network_unavailable', $exception->reason);
        }

        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
    }

    /** TC-09: the same execution never produces a second process or a second result. */
    public function test_the_same_execution_is_rejected_by_its_deterministic_key(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-021-IDEMPOTENT');
        $runner = $this->app->make(CheckRunner::class);

        $first = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');

        try {
            $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');
            self::fail('The duplicate execution produced a second result.');
        } catch (DuplicateCheckExecution $exception) {
            self::assertSame($first->result_key, $exception->resultKey);
        }

        self::assertSame(1, CheckResultRecord::query()->where('run_id', $run->id)->count());
    }

    public function test_the_strict_worker_path_stages_polls_and_accepts_one_bound_result(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-045-ROUNDTRIP');
        $runner = $this->app->make(CheckRunner::class);
        $executionId = 'execution-roundtrip';
        $deadline = time() + 60;

        self::assertNull($runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline));
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($executionId);
        $message = $mailbox->read(MailboxMessageType::REQUEST, 'check-'.$run->id, $delivery, new RedactionContext((string) $run->project_id, $run->id, 'test'));
        $request = CheckExecutionRequest::fromJson($message->content);
        self::assertSame($executionId, $request->executionId);
        $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
        if (! is_dir($outputRoot.'/heartbeats')) {
            mkdir($outputRoot.'/heartbeats', 0700, true);
        }
        if (! is_dir($outputRoot.'/attestations')) {
            mkdir($outputRoot.'/attestations', 0700, true);
        }
        file_put_contents($outputRoot.'/heartbeats/'.$executionId.'.json', json_encode([
            'schema' => 'ai6.check-heartbeat.v1', 'execution_id' => $executionId,
            'checker_boot_id' => str_repeat('a', 32), 'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));
        file_put_contents($outputRoot.'/attestations/checker.json', json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => str_repeat('a', 32),
            'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));

        $mailbox->write(MailboxMessageType::RESULT, 'check-'.$run->id, $delivery, (new CheckExecutionResultDocument(
            $executionId, $run->id, CheckPhase::BEFORE_REVIEW, 'probe-ok', str_repeat('a', 32), $deadline, time(),
            CheckResultState::SUCCEEDED, null, 0, 10, 'ok', $request->sourceTreeSha256,
            $request->sourceTreeSha256, $request->baselineSha256,
            $request->baselineSha256,
            ['side_effects' => false, 'network' => false, 'mutates' => false],
        ))->toJson());

        $publishedBytes = (string) file_get_contents($outputRoot.'/results/'.$delivery.'.json');
        $published = json_decode($publishedBytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('check-'.$run->id, $published['slot_id'] ?? null);
        self::assertSame($delivery, $published['delivery_id'] ?? null);
        $safePublished = json_decode($this->app->make(Redactor::class)->redact(
            $publishedBytes,
            new RedactionContext((string) $run->project_id, $run->id, 'check:before_review'),
        )->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('check-'.$run->id, $safePublished['slot_id'] ?? null);
        self::assertSame($delivery, $safePublished['delivery_id'] ?? null);

        $freshRun = $run->fresh();
        self::assertNotNull($freshRun);
        self::assertSame($run->id, $freshRun->id);
        self::assertSame($delivery, CheckerExecutionProcessor::deliveryId($executionId));
        $record = $runner->dispatchOrCollect($freshRun, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline);
        self::assertNotNull($record);
        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertFileDoesNotExist(config('ai6.execution_mailboxes.checker_root').'/staged/'.$executionId);
        foreach (['claims/'.$executionId.'.json', 'heartbeats/'.$executionId.'.json', 'executions/'.$executionId, 'results/'.$delivery.'.json'] as $relative) {
            self::assertFileDoesNotExist($outputRoot.'/'.$relative);
        }
    }

    public function test_a_new_checker_boot_terminates_an_old_claim_without_reexecution(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-045-BOOT');
        $runner = $this->app->make(CheckRunner::class);
        $executionId = 'execution-old-boot';
        $deadline = time() + 60;
        self::assertNull($runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline));

        $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
        if (! is_dir($outputRoot.'/claims')) {
            mkdir($outputRoot.'/claims', 0700, true);
        }
        if (! is_dir($outputRoot.'/attestations')) {
            mkdir($outputRoot.'/attestations', 0700, true);
        }
        file_put_contents($outputRoot.'/claims/'.$executionId.'.json', json_encode([
            'schema' => 'ai6.check-claim.v1', 'execution_id' => $executionId,
            'checker_boot_id' => str_repeat('a', 32), 'recorded_at' => time(), 'process_started' => true,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($outputRoot.'/attestations/checker.json', json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => str_repeat('b', 32),
            'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));

        try {
            $runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline);
            self::fail('The old checker claim must not be revived by a new boot.');
        } catch (CheckExecutionBoundaryReached $exception) {
            self::assertSame('check_execution_checker_crashed', $exception->reason);
        }
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertFileDoesNotExist(config('ai6.execution_mailboxes.checker_root').'/staged/'.$executionId);
        self::assertFileDoesNotExist($outputRoot.'/claims/'.$executionId.'.json');
        self::assertFileDoesNotExist($outputRoot.'/executions/'.$executionId);
    }

    public function test_a_stale_execution_heartbeat_reaches_a_named_terminal_boundary_and_cleans_up(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-045-STALE-HEARTBEAT');
        $runner = $this->app->make(CheckRunner::class);
        $executionId = 'execution-stale-heartbeat';
        $deadline = time() + 60;
        self::assertNull($runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline));

        $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
        $bootId = str_repeat('c', 32);
        if (! is_dir($outputRoot.'/heartbeats')) {
            mkdir($outputRoot.'/heartbeats', 0700, true);
        }
        file_put_contents($outputRoot.'/heartbeats/'.$executionId.'.json', json_encode([
            'schema' => 'ai6.check-heartbeat.v1', 'execution_id' => $executionId,
            'checker_boot_id' => $bootId, 'recorded_at' => time() - 60,
        ], JSON_THROW_ON_ERROR));
        if (! is_dir($outputRoot.'/attestations')) {
            mkdir($outputRoot.'/attestations', 0700, true);
        }
        file_put_contents($outputRoot.'/attestations/checker.json', json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => $bootId,
            'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));

        try {
            $runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline);
            self::fail('A stale execution heartbeat must be terminal.');
        } catch (CheckExecutionBoundaryReached $exception) {
            self::assertSame('check_execution_heartbeat_stale', $exception->reason);
        }

        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertFileDoesNotExist(config('ai6.execution_mailboxes.checker_root').'/staged/'.$executionId);
        self::assertFileDoesNotExist($outputRoot.'/heartbeats/'.$executionId.'.json');
    }

    public function test_an_expired_execution_deadline_is_terminal_and_cleans_every_artifact(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-045-DEADLINE');
        $executionId = 'execution-expired-deadline';
        $deadline = time() + 2;
        $runner = $this->app->make(CheckRunner::class);
        self::assertNull($runner->dispatchOrCollect(
            $run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline,
        ));
        $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
        foreach (['claims', 'heartbeats', 'executions/'.$executionId] as $directory) {
            if (! is_dir($outputRoot.'/'.$directory)) {
                mkdir($outputRoot.'/'.$directory, 0700, true);
            }
        }
        file_put_contents($outputRoot.'/claims/'.$executionId.'.json', '{}');
        file_put_contents($outputRoot.'/heartbeats/'.$executionId.'.json', '{}');
        file_put_contents($outputRoot.'/executions/'.$executionId.'/artifact', 'stale');
        sleep(2);

        try {
            $runner->dispatchOrCollect(
                $run,
                CheckPhase::BEFORE_REVIEW,
                'probe-ok',
                $executionId,
                $deadline,
            );
            self::fail('An expired execution deadline must be terminal.');
        } catch (CheckExecutionBoundaryReached $exception) {
            self::assertSame('check_execution_deadline_exceeded', $exception->reason);
        }

        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
        self::assertFileDoesNotExist(config('ai6.execution_mailboxes.checker_root').'/staged/'.$executionId);
        self::assertFileDoesNotExist($outputRoot.'/claims/'.$executionId.'.json');
        self::assertFileDoesNotExist($outputRoot.'/heartbeats/'.$executionId.'.json');
        self::assertDirectoryDoesNotExist($outputRoot.'/executions/'.$executionId);
    }

    public function test_each_foreign_result_binding_is_rejected_without_persistence(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        $cases = [
            'schema' => ['schema', 'ai6.check-result.v0', 'check_result_schema_invalid'],
            'execution' => ['execution_id', 'foreign-execution', 'check_result_binding_invalid'],
            'run' => ['run_id', 'foreign-run', 'check_result_binding_invalid'],
            'phase' => ['phase', CheckPhase::FINAL->value, 'check_result_binding_invalid'],
            'profile' => ['profile', 'probe-final', 'check_result_binding_invalid'],
            'boot' => ['checker_boot_id', str_repeat('b', 32), 'check_result_boot_binding_invalid'],
            'source tree' => ['source_tree_sha256', str_repeat('c', 64), 'check_result_binding_invalid'],
            'result tree' => ['result_tree_sha256', str_repeat('d', 64), 'check_result_binding_invalid'],
        ];

        foreach ($cases as $label => [$field, $foreign, $reason]) {
            ['run' => $run] = $this->checkableRun('AI6-045-RESULT-'.strtoupper(str_replace(' ', '-', $label)));
            $runner = $this->app->make(CheckRunner::class);
            $executionId = 'execution-result-'.str_replace(' ', '-', $label);
            $deadline = time() + 60;
            self::assertNull($runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline), $label);
            $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
            $delivery = CheckerExecutionProcessor::deliveryId($executionId);
            $request = CheckExecutionRequest::fromJson($mailbox->read(
                MailboxMessageType::REQUEST,
                'check-'.$run->id,
                $delivery,
                new RedactionContext((string) $run->project_id, $run->id, 'test'),
            )->content);
            $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
            $bootId = str_repeat('a', 32);
            if (! is_dir($outputRoot.'/heartbeats')) {
                mkdir($outputRoot.'/heartbeats', 0700, true);
            }
            file_put_contents($outputRoot.'/heartbeats/'.$executionId.'.json', json_encode([
                'schema' => 'ai6.check-heartbeat.v1', 'execution_id' => $executionId,
                'checker_boot_id' => $bootId, 'recorded_at' => time(),
            ], JSON_THROW_ON_ERROR));
            if (! is_dir($outputRoot.'/attestations')) {
                mkdir($outputRoot.'/attestations', 0700, true);
            }
            file_put_contents($outputRoot.'/attestations/checker.json', json_encode([
                'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => $bootId, 'recorded_at' => time(),
            ], JSON_THROW_ON_ERROR));
            $document = json_decode((new CheckExecutionResultDocument(
                $executionId, $run->id, CheckPhase::BEFORE_REVIEW, 'probe-ok', $bootId, $deadline, time(),
                CheckResultState::SUCCEEDED, null, 0, 10, 'ok', $request->sourceTreeSha256,
                $request->sourceTreeSha256, $request->baselineSha256, $request->baselineSha256,
                ['side_effects' => false, 'network' => false, 'mutates' => false],
            ))->toJson(), true, flags: JSON_THROW_ON_ERROR);
            $document[$field] = $foreign;
            $mailbox->write(MailboxMessageType::RESULT, 'check-'.$run->id, $delivery, json_encode($document, JSON_THROW_ON_ERROR));

            try {
                $runner->dispatchOrCollect($run->fresh() ?? $run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline);
                self::fail('A foreign '.$label.' binding must not be persisted.');
            } catch (CheckExecutionContractException $exception) {
                self::assertSame($reason, $exception->reason, $label);
            }
            self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count(), $label);
        }
    }

    public function test_a_result_arriving_after_its_terminal_deadline_is_not_persisted(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-045-LATE-RESULT');
        $runner = $this->app->make(CheckRunner::class);
        $executionId = 'execution-late-result';
        $deadline = time() + 2;
        self::assertNull($runner->dispatchOrCollect($run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline));
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($executionId);
        $request = CheckExecutionRequest::fromJson($mailbox->read(
            MailboxMessageType::REQUEST,
            'check-'.$run->id,
            $delivery,
            new RedactionContext((string) $run->project_id, $run->id, 'test'),
        )->content);
        $outputRoot = (string) config('ai6.execution_mailboxes.checker_output_root');
        $bootId = str_repeat('a', 32);
        if (! is_dir($outputRoot.'/heartbeats')) {
            mkdir($outputRoot.'/heartbeats', 0700, true);
        }
        file_put_contents($outputRoot.'/heartbeats/'.$executionId.'.json', json_encode([
            'schema' => 'ai6.check-heartbeat.v1', 'execution_id' => $executionId,
            'checker_boot_id' => $bootId, 'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));
        if (! is_dir($outputRoot.'/attestations')) {
            mkdir($outputRoot.'/attestations', 0700, true);
        }
        file_put_contents($outputRoot.'/attestations/checker.json', json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => $bootId, 'recorded_at' => time(),
        ], JSON_THROW_ON_ERROR));
        $mailbox->write(MailboxMessageType::RESULT, 'check-'.$run->id, $delivery, (new CheckExecutionResultDocument(
            $executionId, $run->id, CheckPhase::BEFORE_REVIEW, 'probe-ok', $bootId, $deadline, time(),
            CheckResultState::SUCCEEDED, null, 0, 10, 'ok', $request->sourceTreeSha256,
            $request->sourceTreeSha256, $request->baselineSha256, $request->baselineSha256,
            ['side_effects' => false, 'network' => false, 'mutates' => false],
        ))->toJson());
        sleep(2);

        try {
            $runner->dispatchOrCollect($run->fresh() ?? $run, CheckPhase::BEFORE_REVIEW, 'probe-ok', $executionId, $deadline);
            self::fail('A result after the terminal deadline must not be persisted.');
        } catch (CheckExecutionContractException $exception) {
            self::assertSame('check_result_after_terminal_boundary', $exception->reason);
        }
        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
    }

    /** The persisted result is immutable and its enum guard is enforced by the database. */
    public function test_a_persisted_check_result_is_immutable(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-021-IMMUTABLE');
        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');

        $this->expectExceptionMessageMatches('/check results are immutable/');
        CheckResultRecord::query()->whereKey($record->getKey())->update(['state' => CheckResultState::FAILED->value]);
    }

    /**
     * The one relaxation of that immutability is genuinely narrow.
     *
     * Superseding a live failed result is permitted, because the retry resolver
     * needs it. Superseding a successful one, superseding twice, and changing
     * any other column alongside stay refused.
     */
    public function test_only_a_live_failed_result_may_be_superseded_exactly_once(): void
    {
        $this->bindCheckRuntime([
            'probe-ok' => $this->probeProfile(['ai6-check-ok.php']),
            'probe-fail' => $this->probeProfile(['ai6-check-fail.php']),
        ]);
        ['run' => $run] = $this->checkableRun('AI6-021-SUPERSEDE');
        $runner = $this->app->make(CheckRunner::class);
        $succeeded = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');
        $failed = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-fail');

        // Permitted: the failed result is superseded once.
        self::assertSame(1, CheckResultRecord::query()->whereKey($failed->getKey())
            ->update(['superseded_at' => now()]));
        self::assertNotNull($failed->fresh()?->superseded_at);

        foreach ([
            'a successful result' => fn (): int => CheckResultRecord::query()->whereKey($succeeded->getKey())
                ->update(['superseded_at' => now()]),
            'an already superseded result' => fn (): int => CheckResultRecord::query()->whereKey($failed->getKey())
                ->update(['superseded_at' => now()]),
            'another column alongside' => fn (): int => CheckResultRecord::query()->whereKey($succeeded->getKey())
                ->update(['superseded_at' => now(), 'redacted_output' => 'rewritten']),
        ] as $label => $attempt) {
            try {
                $attempt();
                self::fail('Superseding '.$label.' must be refused.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('check results are immutable', $exception->getMessage(), $label);
            }
        }

        self::assertSame('check ok', trim($succeeded->fresh()->redacted_output));
        self::assertNull($succeeded->fresh()?->superseded_at);
    }
}
