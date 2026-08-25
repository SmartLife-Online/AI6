<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunCancellationMode;
use App\AI6\Runs\RunCancellationService;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-10/TC-12 (AC-11, AC-13, AC-15): the run-bound cancellation saga over the
 * real TicketMutationExecutor phases — interrupted and resumed at every phase
 * boundary — and the real Git compare-and-swap conflict with a foreign,
 * similar-looking status commit and a replay that overwrites nothing.
 *
 * Like the rest of the Git worker suite, these proofs require the POSIX
 * process and effect-lock runtime and self-skip elsewhere.
 */
final class RunCancellationExecutorTest extends TicketUiTestCase
{
    use BuildsManagedControlRuntimeFixture;

    #[DataProvider('cancellationModes')]
    public function test_the_cancellation_saga_survives_a_crash_at_every_phase_and_ends_terminal(
        RunCancellationMode $mode,
        string $targetStatus,
    ): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real cancellation-saga worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->cancellableManagedRun($fixture, 'AI6-CANCEL-'.strtoupper(str_replace('_', '-', $mode->value)), $mode);
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->fresh()->pending_status_operation_id);
        self::assertSame($targetStatus, TicketMutation::query()->findOrFail($operation->id)->target_status);
        $headBefore = trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root']));

        $executor = $this->app->make(TicketMutationExecutor::class);
        $lease = $fixture['lease'];
        $attemptToken = $lease->claim($operation, str_repeat('a', 32));
        self::assertIsInt($attemptToken);

        foreach ([
            ['b', ControlOperationPhase::PREPARED, ControlOperationPhase::COMMIT_PREPARED],
            ['c', ControlOperationPhase::COMMIT_PREPARED, ControlOperationPhase::CONTROL_CONFIRMED],
            ['d', ControlOperationPhase::CONTROL_CONFIRMED, ControlOperationPhase::DB_FINALIZED],
        ] as [$bootSeed, $from, $to]) {
            // A restarted worker takes over between the phases: the lease of
            // the previous boot expires and a new boot reclaims it.
            if ($from !== ControlOperationPhase::PREPARED) {
                self::assertTrue($lease->expire($operation->id, $operation->project_id, $attemptToken));
                $attemptToken = $lease->claim($operation->refresh(), str_repeat($bootSeed, 32));
                self::assertIsInt($attemptToken);
            }

            // A crash at this boundary: the phase write aborts, nothing of the
            // phase survives, and the same attempt resumes the persisted state.
            $this->installProgressCrash($operation->id, $from, $to);
            try {
                $executor->advance($operation->refresh(), $attemptToken);
                self::fail('The cancellation crash injection did not interrupt '.$from->value.'.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('synthetic cancellation progress crash', $exception->getMessage());
            } finally {
                $this->removeProgressCrash();
            }
            self::assertSame($from, $operation->refresh()->phase);
            $interrupted = $run->fresh();
            self::assertSame(RunState::WAITING, $interrupted->state);
            self::assertSame($operation->id, $interrupted->pending_status_operation_id);
            self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);

            $completed = $executor->advance($operation->refresh(), $attemptToken);
            self::assertSame($to === ControlOperationPhase::DB_FINALIZED, $completed);
            self::assertSame($to, $operation->refresh()->phase);
            if ($to !== ControlOperationPhase::DB_FINALIZED) {
                // TC-11: before the confirmed own status commit, run and
                // project lock survive every restart.
                self::assertSame(RunState::WAITING, $run->fresh()->state);
                self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
            }
        }

        // The finalized saga reconciled exactly its own persisted commit.
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        $commitOid = (string) $mutation->prepared_commit_oid;
        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
        $cancelled = $run->fresh();
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($fixture['project']->fresh()->active_run_id);
        $head = trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root']));
        self::assertSame($commitOid, $head);
        self::assertSame($headBefore, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', $commitOid.'^',
        ], $fixture['root'])));
        self::assertStringContainsString('status: '.$targetStatus, $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $commitOid.':'.$bound['relativePath'],
        ], $fixture['root']));

        // A redelivered DB_FINALIZED step is idempotent: no second effect.
        $version = $cancelled->version;
        self::assertTrue($executor->advance($operation->refresh(), $attemptToken));
        self::assertSame($version, $run->fresh()->version);
        self::assertSame($head, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
    }

    /** @return iterable<string, array{RunCancellationMode, string}> */
    public static function cancellationModes(): iterable
    {
        yield 'soft cancel' => [RunCancellationMode::SOFT, 'todo'];
        yield 'block' => [RunCancellationMode::BLOCK, 'blocked'];
        yield 'hard cancel' => [RunCancellationMode::HARD, 'cancelled'];
    }

    public function test_a_real_cas_conflict_with_a_foreign_status_commit_neither_aborts_nor_releases(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real cancellation-conflict worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->cancellableManagedRun($fixture, 'AI6-CANCEL-CONFLICT', RunCancellationMode::SOFT);
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->fresh()->pending_status_operation_id);
        $executor = $this->app->make(TicketMutationExecutor::class);
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('a', 32));
        self::assertIsInt($attemptToken);
        self::assertFalse($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertIsString($mutation->prepared_commit_oid);

        // A foreign, similar-looking status commit moves the control head past
        // the expected parent without touching the run ticket.
        $this->managedFixtureGit(['fetch', $fixture['remote'], 'refs/heads/main'], $fixture['source']);
        $this->managedFixtureGit(['reset', '--hard', 'FETCH_HEAD'], $fixture['source']);
        self::assertNotFalse(file_put_contents($fixture['source'].'/NOTES.md', "Fremder Statuseintrag.\n"));
        $this->managedFixtureGit(['add', 'NOTES.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', implode("\n", [
            'AI6 ticket mutation',
            '',
            'AI6-Operation-ID: '.Str::uuid(),
            'AI6-Reason: Fremde Statusmutation',
        ])], $fixture['source']);
        $foreignCommit = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        // AC-15: the foreign commit produced neither an abort nor a lock
        // release; the conflict parked the run and released only the binding.
        $operation->refresh();
        self::assertSame(ControlOperationState::FAILED, $operation->state);
        self::assertSame(
            hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'control_head_changed'),
            ControlOperationResult::query()->where('control_operation_id', $operation->id)->firstOrFail()->result_binding,
        );
        $parked = $run->fresh();
        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::GIT_CONFLICT, $parked->wait_reason);
        self::assertNull($parked->pending_status_operation_id);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        self::assertSame($foreignCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
        $conflictRequest = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame('git_conflict', $conflictRequest->kind);

        // A replay of the failed operation overwrites nothing.
        $versionAfterConflict = $parked->version;
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        self::assertSame($versionAfterConflict, $run->fresh()->version);
        self::assertSame(2, HumanRequest::query()->where('run_id', $run->id)->count());
        self::assertSame($foreignCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));

        // Refresh with the new expected OID plus a re-authorized decision is
        // the only continuation — and it reaches the terminal run (AC-14).
        $fetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($fetch->id);
        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            $bound['relativePath'],
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);

        $this->app->make(RunCancellationService::class)->request(
            $conflictRequest->fresh(),
            $bound['operator'],
            $conflictRequest->bound_run_version,
            RunCancellationMode::SOFT,
            'Nach OID-Refresh erneut autorisiert.',
            $this->authorization($bound['operator'], $conflictRequest->fresh(), RunCancellationMode::SOFT),
        );
        $rebound = ControlOperation::query()->findOrFail($run->fresh()->pending_status_operation_id);
        self::assertNotSame($operation->id, $rebound->id);
        DB::table('jobs')->delete();
        $reboundToken = $fixture['lease']->claim($rebound, str_repeat('b', 32));
        self::assertIsInt($reboundToken);
        self::assertFalse($executor->advance($rebound, $reboundToken));
        self::assertFalse($executor->advance($rebound->refresh(), $reboundToken));
        self::assertTrue($executor->advance($rebound->refresh(), $reboundToken));

        $secondCommit = (string) TicketMutation::query()->findOrFail($rebound->id)->prepared_commit_oid;
        self::assertSame(RunState::CANCELLED, $run->fresh()->state);
        self::assertNull($fixture['project']->fresh()->active_run_id);
        self::assertSame($secondCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($foreignCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', $secondCommit.'^',
        ], $fixture['root'])));
        self::assertStringContainsString('status: todo', $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $secondCommit.':'.$bound['relativePath'],
        ], $fixture['root']));
    }

    /**
     * Build a run through the real approval and run-start sagas and bind the
     * authorized cancellation decision so that its status operation is queued.
     *
     * @param  array{
     *     administrator: User,
     *     project: Project,
     *     root: string,
     *     remote: string,
     *     source: string,
     *     first_oid: string,
     *     paths: ManagedProjectPath,
     *     runner: HardenedGitRunner,
     *     lease: ProjectOperationLease
     * }  $fixture
     * @return array{run: Run, relativePath: string, operator: User, approver: User}
     */
    private function cancellableManagedRun(array $fixture, string $ticketId, RunCancellationMode $mode): array
    {
        config(['queue.default' => 'database']);
        $relativePath = 'tickets/'.$ticketId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Gebundener Abbruch.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $todo));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add cancellation fixture'], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);
        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            $relativePath,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);

        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $this->addMembership($operator, $fixture['project'], ProjectRole::OPERATOR);
        $readModel = TicketReadModel::query()->where('relative_path', $relativePath)->firstOrFail();
        $selection = $this->approvalSelection($operator);
        $approvalId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $fixture['project']->refresh(),
            $readModel,
            $selection,
            $approvalId,
        );
        $approvalOperation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $fixture['project']->refresh(),
            $readModel,
            $approvalId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $todo,
            'Abbruchfixture freigeben',
            true,
            $selection,
            $snapshot,
            $approvalId,
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($approvalOperation->id);

        $runStart = $this->app->make(QueueRunStart::class)->handle(
            $operator,
            $fixture['project']->refresh(),
            $approvalId,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($runStart->id);
        $run = Run::query()->sole();
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);

        // Bind workspace, checkpoint and the executed preflight so the run
        // carries an answerable wait like every produced intervention wait.
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$fixture['project']->refresh()->project_identifier.'/'.$run->id,
            '/managed/worktrees/'.$run->id,
        );
        $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64));
        $preflight = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::PREFLIGHT->value)->firstOrFail();
        (new ExecuteRunStep($preflight->id))->handle($orchestrator);
        DB::table('jobs')->delete();
        $run = Run::query()->findOrFail($run->id);
        self::assertSame(RunState::RUNNING, $run->state);

        $implement = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $request = $this->app->make(HumanRequestService::class)->open(
            $run,
            new HumanRequestProposal(
                'clarification',
                'Rückfrage',
                'Eine Entscheidung wird benötigt.',
                'Die gebundene Umsetzung benötigt eine Auswahl.',
                'select',
                [new HumanRequestOption('a', 'Option A'), new HumanRequestOption('b', 'Option B')],
                'a',
                [],
                [],
            ),
            (string) Str::uuid(),
            $implement->idempotency_key,
        );

        $actor = $mode->requiresApprover() ? $approver : $operator;
        $this->app->make(RunCancellationService::class)->request(
            $request,
            $actor,
            $request->bound_run_version,
            $mode,
            'Autorisierte Abbruchentscheidung über die Statusmutations-Saga.',
            $this->authorization($actor, $request, $mode),
        );
        DB::table('jobs')->delete();

        return [
            'run' => Run::query()->findOrFail($run->id),
            'relativePath' => $relativePath,
            'operator' => $operator,
            'approver' => $approver,
        ];
    }

    private function approvalSelection(User $attention): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attention->getKey(),
            'manual',
        );
    }

    private function authorization(User $actor, HumanRequest $request, RunCancellationMode $mode): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('cancel-executor-'.$actor->id.'-'.bin2hex(random_bytes(4)));
        $session->start();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);

        return InterventionAuthorization::consumeFresh(
            $proof,
            $actor,
            $guard,
            HumanRequestAnswerController::STEP_UP_ACTION,
            [$request->run_id, $request->id, $request->bound_run_version, $mode->value],
        );
    }

    private function installProgressCrash(
        string $operationId,
        ControlOperationPhase $from,
        ControlOperationPhase $to,
    ): void {
        self::assertTrue(ManagedProjectPath::validOperationIdentifier($operationId));
        DB::unprepared(sprintf(
            "CREATE TEMP TRIGGER cancellation_progress_crash BEFORE UPDATE ON control_operations WHEN OLD.id = '%s' AND OLD.phase = '%s' AND NEW.phase = '%s' BEGIN SELECT RAISE(ABORT, 'synthetic cancellation progress crash'); END",
            $operationId,
            $from->value,
            $to->value,
        ));
    }

    private function removeProgressCrash(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cancellation_progress_crash');
    }
}
