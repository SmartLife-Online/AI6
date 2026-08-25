<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueContractAmendment;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPreflight;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-08, TC-09, TC-11: the contract-amendment saga around the active run.
 *
 * The contracts below are the platform-independent half: queue validation, the
 * published SQLite guards, the exclusive run_base_sha progression and the drift
 * behaviour. The Git-side worker phases (prepare, CAS publish, finalize) carry
 * their own POSIX-gated proof in ContractAmendmentExecutorTest, and the tree
 * proof over real Git objects lives in TicketFileDeltaProofTest.
 */
final class ContractAmendmentTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** @param list<string> $files */
    private function amendmentTicketMarkdown(string $id, string $status, array $files, string $goal = 'Ziel des Tickets.'): string
    {
        $fileLines = implode("\n", array_map(static fn (string $file): string => '  - '.$file, $files));

        return <<<MARKDOWN
        ---
        schema: ai6.ticket.v1
        id: {$id}
        title: "Ticket {$id}"
        status: {$status}
        depends_on: []
        files:
        {$fileLines}
        ---

        # {$id} — Ticket {$id}

        ## Goal

        {$goal}
        MARKDOWN;
    }

    /** @return array{run: Run, project: Project, readModel: TicketReadModel, base: string, actor: User, ticketId: string} */
    private function amendableRun(string $ticketId): array
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $attention = $this->createUser(['email' => 'attention-'.$ticketId.'@example.test']);
        $fixture = $this->completedApproval($ticketId, attentionUser: $attention);
        $run = $this->finalizedRun($fixture);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $run));

        $base = $this->amendmentTicketMarkdown($ticketId, 'in_progress', ['app/Example.php']);
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $base,
            'tickets/'.$ticketId.'.md',
            TicketValidationProfile::GENERIC_V1,
        );
        self::assertNotNull($projection->contractHash);
        $readModel = TicketReadModel::query()
            ->where('project_id', $run->project_id)
            ->where('relative_path', 'tickets/'.$ticketId.'.md')
            ->firstOrFail();
        // The run-start operation is still bound control_confirmed at the run
        // base, so the published read-model guard accepts the in_progress
        // projection exactly like the real run start publishes it.
        self::assertSame(1, DB::table('ticket_read_models')->where('id', $readModel->id)->update([
            'control_operation_id' => $run->status_operation_id,
            'control_commit' => $run->run_base_sha,
            'blob_sha' => hash('sha256', 'blob '.strlen($base)."\0".$base),
            'redacted_content' => $base,
            'ticket_contract_sha256' => $projection->contractHash,
            'document_state' => 'valid',
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));

        return [
            'run' => $run->refresh(),
            'project' => $fixture['project']->refresh(),
            'readModel' => $readModel->refresh(),
            'base' => $base,
            'actor' => $fixture['operator'],
            'ticketId' => $ticketId,
        ];
    }

    /** @param array{run: Run, project: Project, readModel: TicketReadModel, base: string, actor: User, ticketId: string} $fixture */
    private function queueAmendment(array $fixture, string $target, ?string $operationId = null): ControlOperation
    {
        return $this->app->make(QueueContractAmendment::class)->handle(
            $fixture['actor'],
            $fixture['project'],
            $fixture['run'],
            $fixture['readModel'],
            $operationId ?? (string) Str::uuid(),
            $target,
            'Vertragsanpassung für den aktiven Run',
            true,
        );
    }

    /** AC-07: the queued amendment binds run, run base and parameters into the published guards. */
    public function test_queue_contract_amendment_binds_run_base_and_stays_idempotent(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Präzisiertes Ziel.');

        $operationId = (string) Str::uuid();
        $operation = $this->queueAmendment($fixture, $target, $operationId);

        self::assertSame('contract_amendment', $operation->operation_type->value);
        self::assertSame($fixture['run']->run_base_sha, $operation->expected_control_commit);
        $parameters = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($fixture['run']->id, $parameters['run_id']);
        self::assertSame('tickets/'.$fixture['ticketId'].'.md', $parameters['relative_path']);

        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertSame('in_progress', $mutation->source_status);
        self::assertSame('in_progress', $mutation->target_status);
        self::assertSame($fixture['readModel']->blob_sha, $mutation->expected_ticket_blob_sha);

        // The identical request is idempotent; a different target for the same
        // identifier is a named conflict.
        $again = $this->queueAmendment($fixture, $target, $operationId);
        self::assertSame($operation->id, $again->id);
    }

    /** TC-11: a same-run amendment must not adopt an instruction path into the files scope. */
    public function test_amendment_rejects_instruction_path_adoption(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-INSTR');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php', 'AGENTS.md']);

        try {
            $this->queueAmendment($fixture, $target);
            self::fail('An instruction path must never join the ticket files through an amendment.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('instruction_scope_extension_forbidden', $conflict->conflict);
        }
        self::assertSame(0, ControlOperation::query()->where('operation_type', 'contract_amendment')->count());
    }

    /**
     * AC-12, TC-11: the nested discovery form is an instruction path too, so a
     * nested AGENTS.md cannot ride into the files scope either.
     */
    public function test_amendment_rejects_a_nested_instruction_path(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-NESTED');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php', 'app/sub/AGENTS.md']);

        try {
            $this->queueAmendment($fixture, $target);
            self::fail('A nested instruction path must never join the ticket files through an amendment.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('instruction_scope_extension_forbidden', $conflict->conflict);
        }
        self::assertSame(0, ControlOperation::query()->where('operation_type', 'contract_amendment')->count());
    }

    /**
     * AC-05, TC-03: paths the amendment adds to the files list consume the same
     * freigegebene max_added_scope_paths; beyond it nothing is taken up at all.
     */
    public function test_amendment_beyond_the_added_scope_path_limit_takes_up_nothing(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-LIMIT');
        $run = $fixture['run'];
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $maxAddedScopePaths = $this->app->make(RunLimitPolicy::class)->effective($run)['max_added_scope_paths'];
        for ($i = 0; $i < $maxAddedScopePaths; $i++) {
            $run = $orchestrator->applyScopeDecision(
                $run->fresh(),
                'app/AI6/Runs/Filler'.$i.'.php',
                true,
                null,
                $maxAddedScopePaths,
                $canonicalJson,
                'auto_allow',
            );
        }
        $fixture['run'] = $run->fresh();

        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php', 'app/Another.php']);
        try {
            $this->queueAmendment($fixture, $target);
            self::fail('An amendment beyond the approved path limit must be refused.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('scope_path_limit_exceeded', $conflict->conflict);
        }

        // Nothing was taken up and no operation exists.
        self::assertSame(0, ControlOperation::query()->where('operation_type', 'contract_amendment')->count());
        self::assertSame($maxAddedScopePaths, $run->fresh()->added_scope_paths_count);
        self::assertSame(0, ScopeDecision::query()
            ->where('run_id', $run->id)->where('path', 'app/Another.php')->count());
    }

    /**
     * A confirmed amendment moves version, scope and prompt binding; it is
     * therefore refused while an implement step is actually executing.
     */
    public function test_amendment_is_refused_while_an_implement_step_runs(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-RUNNING');
        $job = ExecutionJob::query()->where('run_id', $fixture['run']->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        self::assertSame(1, ExecutionJob::query()->whereKey($job->getKey())
            ->update(['state' => ExecutionJobState::RUNNING]));

        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Konkurrierendes Ziel.');
        try {
            $this->queueAmendment($fixture, $target);
            self::fail('An amendment must not move the contract underneath a running implement step.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('amendment_step_running', $conflict->conflict);
        }
        self::assertSame(0, ControlOperation::query()->where('operation_type', 'contract_amendment')->count());
    }

    /**
     * N2: a path this run already rejected must not re-enter the effective
     * scope through the ticket files list.
     */
    public function test_an_amendment_readding_a_rejected_path_is_refused(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-READD');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $run = $orchestrator->applyScopeDecision(
            $fixture['run'],
            'docs/rejected.md',
            false,
            null,
            12,
            $canonicalJson,
            'human_rejected',
        );
        $fixture['run'] = $run->fresh();

        $target = $this->amendmentTicketMarkdown(
            $fixture['ticketId'],
            'in_progress',
            ['app/Example.php', 'docs/rejected.md'],
        );
        try {
            $this->queueAmendment($fixture, $target);
            self::fail('A rejected path must not return through an amendment.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('amendment_readds_rejected_path', $conflict->conflict);
        }

        // Nothing was queued and the immutable decision still stands.
        self::assertSame(0, ControlOperation::query()->where('operation_type', 'contract_amendment')->count());
        self::assertSame('rejected', ScopeDecision::query()
            ->where('run_id', $run->id)->where('path', 'docs/rejected.md')->firstOrFail()->outcome);
        self::assertSame(0, $run->fresh()->added_scope_paths_count);
    }

    /** The amendment never moves the ticket status. */
    public function test_amendment_requires_the_unchanged_in_progress_status(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-STATUS');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'ready', ['app/Example.php']);

        try {
            $this->queueAmendment($fixture, $target);
            self::fail('An amendment must keep the ticket status in_progress.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('amendment_status_not_in_progress', $conflict->conflict);
        }
    }

    /** TC-08 (DB half): only run_base_sha moves; approval and initial_run_base_sha stay. */
    public function test_apply_contract_amendment_moves_only_the_run_base_and_invalidates_evidence(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-APPLY');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        self::assertTrue($orchestrator->hasEffectiveCheckpoint($run));
        // An earlier approved addition survives the amendment inside the
        // recomputed effective scope and keeps its counter (AC-05); it already
        // invalidates the checkpoint evidence of the pre-extension state.
        $run = $orchestrator->applyScopeDecision($run, 'docs/extra.md', true, null, 12, $canonicalJson, 'human_approved');
        self::assertFalse($orchestrator->hasEffectiveCheckpoint($run));
        $approvalBefore = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $initialBase = $run->initial_run_base_sha;
        $checkpointBefore = [$run->checkpoint_commit_sha, $run->checkpoint_tree_sha, $run->checkpoint_diff_hash];

        $newBase = str_repeat('d', 64);
        $amended = $orchestrator->applyContractAmendment(
            $run->fresh(),
            $newBase,
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['ticket_files' => ['app/Example.php', 'app/New.php'], 'project_scope' => []],
            str_repeat('3', 64),
            ['values' => []],
            (string) $run->config_hash,
            ['rendered_prompts' => ['implementation' => 'Neuer Prompt.']],
            str_repeat('4', 64),
            $canonicalJson,
            12,
        );

        self::assertSame($newBase, $amended->run_base_sha);
        self::assertSame($initialBase, $amended->initial_run_base_sha);
        self::assertSame(str_repeat('1', 64), $amended->ticket_blob_sha);
        self::assertSame(str_repeat('2', 64), $amended->ticket_contract_sha256);
        self::assertSame(['app/Example.php', 'app/New.php'], $amended->scope_snapshot['ticket_files']);
        // The recomputed effective scope carries the amended initial scope plus
        // the previously approved addition.
        self::assertContains('app/New.php', $amended->effective_scope_snapshot);
        self::assertContains('docs/extra.md', $amended->effective_scope_snapshot);
        // AC-05: the two paths the amendment adds to the ticket files consume
        // the same counter as an approved addition — the earlier docs/extra.md
        // decision is not counted a second time.
        self::assertSame(3, $amended->added_scope_paths_count);
        $reasons = ScopeDecision::query()->where('run_id', $run->id)->orderBy('path')->pluck('reason', 'path')->all();
        self::assertSame([
            'app/Example.php' => 'amendment',
            'app/New.php' => 'amendment',
            'docs/extra.md' => 'human_approved',
        ], $reasons);

        // A replayed amendment with the same files list consumes nothing more.
        $replayed = $orchestrator->applyContractAmendment(
            $amended,
            $newBase,
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['ticket_files' => ['app/Example.php', 'app/New.php'], 'project_scope' => []],
            str_repeat('3', 64),
            ['values' => []],
            (string) $run->config_hash,
            ['rendered_prompts' => ['implementation' => 'Neuer Prompt.']],
            str_repeat('4', 64),
            $canonicalJson,
            12,
        );
        self::assertSame(3, $replayed->added_scope_paths_count);

        // Evidence of the old state is ineffective but stays readable (AC-10).
        self::assertSame(2, $amended->evidence_epoch);
        self::assertFalse($orchestrator->hasEffectiveCheckpoint($amended));
        self::assertSame($checkpointBefore, [$amended->checkpoint_commit_sha, $amended->checkpoint_tree_sha, $amended->checkpoint_diff_hash]);

        // The approval record never moves (AC-08).
        $approvalAfter = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        self::assertSame($approvalBefore->approved_control_sha, $approvalAfter->approved_control_sha);
        self::assertSame($approvalBefore->scope_hash, $approvalAfter->scope_hash);
    }

    /** TC-09: a terminal amendment conflict parks the run behind git_base_changed and keeps the project lock. */
    public function test_a_terminal_amendment_conflict_parks_the_run_and_preserves_the_lock(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-PARK');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Konfliktziel.');
        $operation = $this->queueAmendment($fixture, $target);
        self::assertSame(RunState::RUNNING, $fixture['run']->fresh()->state);

        // The park opens the abort request; its notification is queued like in
        // the worker runtime instead of being delivered inline.
        config(['queue.default' => 'database']);
        $this->app->make(TicketMutationExecutor::class)->parkAmendedRunOnConflict($operation);

        $run = $fixture['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $run->wait_reason);
        // No silent rebase: the run base and the project lock survive (AC-13).
        self::assertSame($fixture['run']->run_base_sha, $run->run_base_sha);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        // AI6-026 AC-05: the parked wait now carries its controlled-abort request.
        self::assertSame('git_base_changed', HumanRequest::query()
            ->where('run_id', $run->id)->where('resolution_state', 'open')->sole()->kind);
    }

    /**
     * AC-13: drift is answered from every non-terminal state.
     *
     * An amendment is normally queued for a run that already waits behind
     * contract_change — its compare-and-swap is that wait's allowlisted
     * resolver. When the resolver fails, the run must not keep advertising it.
     */
    public function test_a_terminal_amendment_conflict_reparks_a_waiting_run(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-REPARK');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Wartezustandsziel.');
        $operation = $this->queueAmendment($fixture, $target);

        // The run waits behind contract_change while the amendment runs.
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $waiting = $orchestrator->transition(
            $fixture['run']->fresh(),
            $fixture['run']->fresh()->version,
            RunState::WAITING,
            $fixture['run']->phase,
            WaitReason::CONTRACT_CHANGE,
        );
        self::assertSame(WaitReason::CONTRACT_CHANGE, $waiting->wait_reason);

        config(['queue.default' => 'database']);
        $this->app->make(TicketMutationExecutor::class)->parkAmendedRunOnConflict($operation);

        $run = $fixture['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $run->wait_reason);
        self::assertSame($fixture['run']->run_base_sha, $run->run_base_sha);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);

        // Idempotent: a replayed conflict neither moves the run nor its version.
        $version = $run->version;
        $this->app->make(TicketMutationExecutor::class)->parkAmendedRunOnConflict($operation);
        self::assertSame($version, $fixture['run']->fresh()->version);
    }

    /** A queued run — claimed, first worker job not started — is parked too. */
    public function test_a_terminal_amendment_conflict_parks_a_queued_run(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-QUEUED');
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Queuezustandsziel.');
        $operation = $this->queueAmendment($fixture, $target);
        self::assertSame(1, Run::query()->whereKey($fixture['run']->id)->update([
            'state' => RunState::QUEUED,
            'wait_reason' => null,
            'version' => DB::raw('version + 1'),
        ]));

        config(['queue.default' => 'database']);
        $this->app->make(TicketMutationExecutor::class)->parkAmendedRunOnConflict($operation);

        $run = $fixture['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $run->wait_reason);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
    }

    /** Preflight accepts diverged scope/config/prompt bindings only behind a confirmed amendment. */
    public function test_preflight_accepts_amended_bindings_only_with_a_confirmed_amendment(): void
    {
        $fixture = $this->amendableRun('AI6-020-AMEND-PREFLIGHT');
        $run = $fixture['run'];
        $preflight = $this->app->make(RunPreflight::class);
        self::assertNull($preflight->failureCode($run));

        // Divergence without a confirmed amendment is a stale binding.
        self::assertSame(1, Run::query()->whereKey($run->id)->update([
            'scope_hash' => str_repeat('e', 64),
            'version' => DB::raw('version + 1'),
        ]));
        self::assertSame('snapshot_binding_changed', $preflight->failureCode($run->fresh()));

        // The same divergence behind a completed amendment bound to the current
        // run base is the legitimate amended state.
        $target = $this->amendmentTicketMarkdown($fixture['ticketId'], 'in_progress', ['app/Example.php'], 'Bestätigtes Ziel.');
        $fixture['run'] = $run->fresh();
        $operation = $this->queueAmendment($fixture, $target);
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $newBase = str_repeat('d', 64);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $newBase,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Amendment-Operation.',
        ]);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release($operation->id, $operation->project_id, $attemptToken));

        $amended = $this->app->make(RunOrchestrator::class)->applyContractAmendment(
            $run->fresh(),
            $newBase,
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['ticket_files' => ['app/Example.php'], 'project_scope' => []],
            str_repeat('3', 64),
            (array) $run->config_snapshot,
            (string) $run->config_hash,
            (array) $run->prompt_snapshot,
            (string) $run->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );

        self::assertNull($preflight->failureCode($amended));

        // A config divergence is never amendment-tolerated: the amendment
        // changes exactly the ticket file and finalizeAmendedRun proves the
        // effective configuration stayed byte-stable, so a moved config_hash
        // is foreign drift even behind this confirmed amendment.
        self::assertSame(1, Run::query()->whereKey($amended->id)->update([
            'config_hash' => str_repeat('c', 64),
            'version' => DB::raw('version + 1'),
        ]));
        self::assertSame('snapshot_binding_changed', $preflight->failureCode($amended->fresh()));
    }
}
