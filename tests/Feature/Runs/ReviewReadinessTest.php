<?php

namespace Tests\Feature\Runs;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\CanonicalJson;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\GateState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\ReviewBlocker;
use App\AI6\Runs\ReviewReadinessDecision;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ReviewReadinessTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_readiness_orders_scope_check_drift_and_checkpoint_blockers(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-RDY');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindActualChangedPaths(
            $fixture['run'], $fixture['run']->version, ['outside/Unknown.php'], $this->app->make(CanonicalJson::class),
        );
        $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('4', 64), str_repeat('5', 64), str_repeat('6', 64));
        $run->project()->update(['control_oid' => str_repeat('9', 64)]);

        $decision = $orchestrator->reviewReadiness($run->fresh() ?? $run, str_repeat('7', 64), str_repeat('8', 64));
        $codes = array_map(static fn ($blocker): string => $blocker->code, $decision->blockers);

        self::assertFalse($decision->ready());
        self::assertSame('scope_unresolved', $codes[0]);
        self::assertContains('required_check_missing', $codes);
        self::assertContains('control_head_drift', $codes);
        self::assertSame('checkpoint_diff_mismatch', $codes[array_key_last($codes)]);
    }

    public function test_open_gates_allow_review_but_block_later_effects_and_evidence_is_invalidated(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-GATE');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $ticket = $this->app->make(TicketV1Parser::class)->parse($this->gateTicket());

        $orchestrator->prepareGates($run, $ticket);
        $orchestrator->prepareGates($run, $ticket);
        self::assertSame(2, RunGate::query()->where('run_id', $run->id)->count());
        self::assertSame(2, RunGate::query()->where('run_id', $run->id)->where('state', GateState::OPEN->value)
            ->where('blocks_candidate', true)->where('blocks_final_commit', true)->where('blocks_push', true)->count());

        $treeBinding = str_repeat('b', 64);
        $this->persistSuccessfulChecks($run, $treeBinding);
        $decision = $orchestrator->reviewReadiness($run, (string) $run->checkpoint_diff_hash, $treeBinding);
        self::assertTrue($decision->ready());
        self::assertSame(['EXT-01', 'MG-01'], $decision->openGates);

        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $closed = $orchestrator->authorizeGateEvidence($run, 'MG-01', $approver->id, 'protocol:sha256:'.str_repeat('a', 64));
        self::assertSame(GateState::CLOSED, $closed->state);

        $advanced = $orchestrator->bindCheckpoint($run->fresh() ?? $run, ($run->fresh() ?? $run)->version, str_repeat('8', 64), str_repeat('5', 64), str_repeat('6', 64));
        $reopened = RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->firstOrFail();
        self::assertSame(GateState::OPEN, $reopened->state);
        self::assertNotNull($reopened->invalidated_at);
        self::assertSame('protocol:sha256:'.str_repeat('a', 64), $reopened->evidence_reference);

        try {
            $orchestrator->bindCheckpoint($advanced, $advanced->version, (string) $run->checkpoint_commit_sha, (string) $run->checkpoint_tree_sha, (string) $run->checkpoint_diff_hash);
            self::fail('A superseded checkpoint was rebound.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('checkpoint_rollback', $conflict->reason);
        }
    }

    public function test_empty_gate_marker_persists_no_gate_and_ticket_drift_persists_nothing(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-NO-GATE');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $ticket = $this->app->make(TicketV1Parser::class)->parse(<<<'MARKDOWN'
            ---
            schema: ai6.ticket.v1
            id: AI6-022-NO-GATE
            title: "Gate-Test"
            status: ready
            depends_on: []
            ---

            ## Manual and External Gates

            None.
            MARKDOWN);

        $orchestrator->prepareGates($run, $ticket);
        self::assertSame(0, RunGate::query()->where('run_id', $run->id)->count());

        try {
            $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()), str_repeat('f', 64));
            self::fail('Ticket drift created gates from unapproved content.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('ticket_contract_drift', $conflict->reason);
        }
        self::assertSame(0, RunGate::query()->where('run_id', $run->id)->count());
    }

    public function test_gate_authorization_uses_the_active_approver_policy_and_validates_references(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-GATE-AUTH');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()));

        $administrator = $this->createUser(['is_global_admin' => true]);
        $this->addMembership($administrator, $fixture['project'], ProjectRole::ADMIN);
        $inactiveApprover = $this->createUser(['is_active' => false]);
        $this->addMembership($inactiveApprover, $fixture['project'], ProjectRole::APPROVER);

        foreach ([$administrator, $inactiveApprover] as $actor) {
            try {
                $orchestrator->authorizeGateEvidence($run, 'MG-01', $actor->id, 'protocol:sha256:'.str_repeat('a', 64));
                self::fail('A non-authorized project actor closed a gate.');
            } catch (RunTransitionConflict $conflict) {
                self::assertSame('gate_evidence_unauthorized', $conflict->reason);
            }
        }

        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        try {
            $orchestrator->authorizeGateEvidence($run, 'MG-01', $approver->id, "invalid reference\n");
            self::fail('An unsafe evidence reference was persisted.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('gate_evidence_binding_invalid', $conflict->reason);
        }
    }

    public function test_each_checkpoint_change_advances_the_invalidation_boundary(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-EPOCH');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $before = RunEvent::query()->where('run_id', $run->id)->where('event_type', 'evidence.invalidated')->count();

        $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('8', 64), str_repeat('5', 64), str_repeat('6', 64));
        $orchestrator->bindCheckpoint($run, $run->version, str_repeat('9', 64), str_repeat('5', 64), str_repeat('6', 64));

        self::assertSame($before + 2, RunEvent::query()->where('run_id', $run->id)->where('event_type', 'evidence.invalidated')->count());
    }

    public function test_readiness_persists_only_redacted_blocker_messages(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-REDACT');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $decision = new ReviewReadinessDecision([
            new ReviewBlocker('scope_unresolved', 'Geänderter Pfad password=hunter2'),
        ], []);

        $stored = $this->app->make(RunOrchestrator::class)->recordReviewReadiness($run, $decision);

        self::assertSame('Geänderter Pfad password=[REDACTED:SECRET]', $stored->review_blockers[0]['message'] ?? null);
    }

    public function test_checks_from_an_older_evidence_epoch_are_not_current(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-CHECK-EPOCH');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $treeBinding = str_repeat('b', 64);
        $this->persistSuccessfulChecks($run, $treeBinding);
        $run = $this->app->make(RunOrchestrator::class)->bindActualChangedPaths(
            $run,
            $run->version,
            ['app/Example.php'],
            $this->app->make(CanonicalJson::class),
        );

        $decision = $this->app->make(RunOrchestrator::class)
            ->reviewReadiness($run, (string) $run->checkpoint_diff_hash, $treeBinding);

        self::assertContains('required_check_missing', array_map(static fn ($blocker): string => $blocker->code, $decision->blockers));
    }

    public function test_check_evidence_epoch_is_explicit_immutable_and_part_of_live_uniqueness(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-CHECK-EPOCH-GUARD');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $tree = str_repeat('b', 64);
        $this->persistSuccessfulChecks($run, $tree);
        $first = CheckResultRecord::query()->where('run_id', $run->id)->firstOrFail();

        try {
            $first->forceFill(['evidence_epoch' => $first->evidence_epoch + 1])->save();
            self::fail('A persisted check changed its evidence epoch.');
        } catch (QueryException) {
            self::assertSame($run->evidence_epoch, $first->fresh()?->evidence_epoch);
        }

        $next = $first->replicate(['id', 'result_key', 'evidence_epoch', 'created_at', 'updated_at']);
        $next->id = (string) Str::uuid();
        $next->evidence_epoch = $run->evidence_epoch + 1;
        $next->result_key = CheckResult::key($run->id, $next->evidence_epoch, CheckPhase::BEFORE_REVIEW, $next->profile, $tree);
        $next->save();
        self::assertSame(2, CheckResultRecord::query()->where('run_id', $run->id)->where('tree_sha', $tree)->count());

        $missingEpoch = $first->getAttributes();
        $missingEpoch['id'] = (string) Str::uuid();
        $missingEpoch['result_key'] = hash('sha256', 'missing-epoch');
        unset($missingEpoch['evidence_epoch'], $missingEpoch['created_at'], $missingEpoch['updated_at']);
        try {
            DB::table('check_results')->insert($missingEpoch);
            self::fail('A check result without an explicit evidence epoch was inserted.');
        } catch (QueryException) {
            self::assertSame(2, CheckResultRecord::query()->where('run_id', $run->id)->count());
        }
    }

    public function test_a_contract_amendment_on_an_unchanged_tree_rechecks_the_new_epoch_and_becomes_ready(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-AMENDED-CHECK-EPOCH');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $tree = (string) $run->checkpoint_tree_sha;
        $diff = (string) $run->checkpoint_diff_hash;
        $ticketContract = (string) DB::table('ticket_approvals')->where('id', $run->ticket_approval_id)
            ->value('ticket_contract_sha256');
        $this->persistSuccessfulChecks($run, $tree);
        self::assertTrue($orchestrator->reviewReadiness($run, $diff, $tree)->ready());

        $amended = $orchestrator->applyContractAmendment(
            $run,
            (string) $run->run_base_sha,
            str_repeat('a', 64),
            $ticketContract,
            (array) $run->scope_snapshot,
            (string) $run->scope_hash,
            (array) $run->config_snapshot,
            (string) $run->config_hash,
            (array) $run->prompt_snapshot,
            (string) $run->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );
        $checkpointed = $orchestrator->bindCheckpoint(
            $amended,
            $amended->version,
            str_repeat('c', 64),
            $tree,
            $diff,
        );
        $this->persistSuccessfulChecks($checkpointed, $tree);

        $decision = $orchestrator->reviewReadiness($checkpointed, $diff, $tree);
        $ready = $orchestrator->recordReviewReadiness($checkpointed, $decision);

        self::assertTrue($decision->ready(), json_encode($decision->blockers, JSON_THROW_ON_ERROR));
        self::assertSame('ready', $ready->review_readiness_state);
        self::assertSame(
            [$run->evidence_epoch, $checkpointed->evidence_epoch],
            CheckResultRecord::query()->where('run_id', $run->id)->select('evidence_epoch')
                ->distinct()->orderBy('evidence_epoch')->pluck('evidence_epoch')->all(),
        );
    }

    #[DataProvider('blockingCheckStates')]
    public function test_each_non_successful_check_state_stays_a_named_readiness_blocker(CheckResultState $state, string $ticketSuffix): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-'.$ticketSuffix);
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $treeBinding = str_repeat('b', 64);
        $this->persistChecks($run, $state, $treeBinding);

        $decision = $this->app->make(RunOrchestrator::class)
            ->reviewReadiness($run, (string) $run->checkpoint_diff_hash, $treeBinding);
        $codes = array_map(static fn ($blocker): string => $blocker->code, $decision->blockers);

        self::assertContains('required_check_'.$state->value, $codes);
    }

    public function test_a_successful_check_for_an_older_tree_does_not_make_the_run_ready(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-022-OLD-TREE');
        $run = $fixture['run']->fresh() ?? $fixture['run'];
        $this->persistSuccessfulChecks($run, str_repeat('a', 64));
        $currentTree = str_repeat('b', 64);

        $decision = $this->app->make(RunOrchestrator::class)
            ->reviewReadiness($run, (string) $run->checkpoint_diff_hash, $currentTree);
        $codes = array_map(static fn ($blocker): string => $blocker->code, $decision->blockers);

        self::assertContains('required_check_tree_mismatch', $codes);
        self::assertFalse($decision->ready());
    }

    /** @return iterable<string, array{CheckResultState, string}> */
    public static function blockingCheckStates(): iterable
    {
        yield 'failed' => [CheckResultState::FAILED, 'FAILED'];
        yield 'timed out' => [CheckResultState::TIMED_OUT, 'TIMEOUT'];
        yield 'tool unavailable' => [CheckResultState::TOOL_UNAVAILABLE, 'NO-TOOL'];
    }

    private function persistSuccessfulChecks(Run $run, string $treeBinding): void
    {
        $this->persistChecks($run, CheckResultState::SUCCEEDED, $treeBinding);
    }

    private function persistChecks(Run $run, CheckResultState $firstState, string $treeBinding): void
    {
        $profiles = ($run->config_snapshot ?? [])['values']['checks']['before_review'] ?? [];
        foreach (array_values(array_unique(array_filter($profiles, 'is_string'))) as $index => $profile) {
            $state = $index === 0 ? $firstState : CheckResultState::SUCCEEDED;
            CheckResultRecord::query()->create([
                'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
                'evidence_epoch' => $run->evidence_epoch,
                'profile' => $profile, 'state' => $state, 'reason' => $state === CheckResultState::SUCCEEDED ? null : $state->value,
                'exit_code' => $state === CheckResultState::SUCCEEDED ? 0 : 1,
                'duration_ms' => 1, 'redacted_output' => 'ok', 'tree_sha' => $treeBinding, 'result_tree_sha' => $treeBinding,
                'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
                'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, $profile, $treeBinding),
            ]);
        }
    }

    private function gateTicket(): string
    {
        return <<<'MARKDOWN'
            ---
            schema: ai6.ticket.v1
            id: AI6-022-GATE
            title: "Gate-Test"
            status: ready
            depends_on: []
            ---

            ## Goal

            Gatebindung prüfen.

            ## Manual and External Gates

            - **MG-01** Menschliche Prüfung.
            - **EXT-01** Externe Evidenz.
            MARKDOWN;
    }
}
