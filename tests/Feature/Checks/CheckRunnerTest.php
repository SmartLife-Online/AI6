<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\DuplicateCheckExecution;
use App\AI6\Checks\Models\CheckResultRecord;
use Illuminate\Database\QueryException;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-01 result states, TC-06 mutation detection, TC-07 redaction and TC-08 phase binding
 * against real processes.
 */
final class CheckRunnerTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

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

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');

        self::assertSame($record->tree_sha, $record->result_tree_sha);
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
