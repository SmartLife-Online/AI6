<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueContractAmendment;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\TicketFileDeltaProof;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalClaimStarter;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-08/TC-09: the contract-amendment saga driven through the real worker.
 *
 * ContractAmendmentTest proves the platform-independent half (queue validation,
 * published guards, run rebinding, drift parking). This test executes the
 * Git-side phases — prepare, compare-and-swap publish, finalize — against the
 * real managed repository and remote, so the tree proof before and after the
 * takeover, the run_base_sha progression and the compare-and-swap conflict are
 * observed on real objects instead of a fixture.
 */
final class ContractAmendmentExecutorTest extends TicketUiTestCase
{
    use BuildsManagedControlRuntimeFixture;

    /**
     * @return array{fixture: array<string, mixed>, run: Run, readModel: TicketReadModel, actor: User, path: string, content: string}
     */
    private function amendableManagedRun(string $ticketId): array
    {
        $fixture = $this->managedFixture();
        $relativePath = 'tickets/'.$ticketId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Gebundener Runstart.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $todo));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add amendment fixture'], $fixture['source']);
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
        $selection = $this->amendmentApprovalSelection();
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
            'Runstart freigeben',
            true,
            $selection,
            $snapshot,
            $approvalId,
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($approvalOperation->id);
        self::assertSame('complete', TicketApproval::query()->findOrFail($approvalId)->saga_phase);

        $runStart = $this->app->make(ApprovalClaimStarter::class)->start(
            $operator,
            $fixture['project']->refresh(),
            $approvalId,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($runStart->id);

        $run = Run::query()->sole();
        $project = $fixture['project']->refresh();
        self::assertSame($run->run_base_sha, $project->control_oid);
        self::assertSame($run->initial_run_base_sha, $run->run_base_sha);

        // The first worker job moves the claimed run from queued to running;
        // the amendment saga parks exactly this state on a conflict.
        $run = $this->app->make(RunOrchestrator::class)
            ->transition($run, $run->version, RunState::RUNNING, $run->phase, null);

        $readModel = TicketReadModel::query()->where('relative_path', $relativePath)->firstOrFail();
        // The run start published the in_progress projection at the run base;
        // the amendment binds exactly that state.
        self::assertSame($run->run_base_sha, $readModel->control_commit);
        $inProgress = $readModel->redacted_content;
        self::assertStringContainsString('status: in_progress', $inProgress);

        return [
            'fixture' => $fixture,
            'run' => $run,
            'readModel' => $readModel,
            'actor' => $fixture['administrator'],
            'path' => $relativePath,
            'content' => $inProgress,
        ];
    }

    public function test_the_worker_publishes_the_amendment_and_rebinds_the_run(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real contract-amendment worker proof requires the POSIX process and effect-lock runtime.');
        }

        $bound = $this->amendableManagedRun('AI6-AMEND-OK');
        $fixture = $bound['fixture'];
        $run = $bound['run'];
        $initialBase = $run->initial_run_base_sha;
        $parentOid = $run->run_base_sha;
        $configHashBefore = (string) $run->config_hash;
        $approvedControlBefore = TicketApproval::query()->findOrFail($run->ticket_approval_id)->approved_control_sha;
        $scopeHashBefore = (string) $run->scope_hash;
        $target = str_replace('Gebundener Runstart.', 'Präzisiertes Ziel.', $bound['content']);
        self::assertNotSame($bound['content'], $target);

        $operation = $this->app->make(QueueContractAmendment::class)->handle(
            $bound['actor'],
            $fixture['project']->refresh(),
            $run,
            $bound['readModel'],
            (string) Str::uuid(),
            $target,
            'Vertragsanpassung für den aktiven Run',
            true,
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationPhase::DB_FINALIZED, $operation->phase);
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        $commitOid = (string) $mutation->prepared_commit_oid;

        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier),
        );
        // The tree proof the saga runs before and after the takeover, executed
        // once more here over the confirmed objects (AC-09).
        $this->app->make(TicketFileDeltaProof::class)->assertOnlyPathChanged(
            $repository,
            $parentOid,
            $commitOid,
            $bound['path'],
            new RedactionContext((string) $fixture['project']->getKey(), $operation->id, 'amendment-delta-proof'),
        );
        self::assertSame(
            [$bound['path']],
            preg_split('/\r?\n/', trim($this->managedFixtureGit([
                'diff-tree', '--no-commit-id', '--name-only', '-r', $commitOid,
            ], $repository))),
        );
        self::assertSame($parentOid, trim($this->managedFixtureGit(['rev-parse', $commitOid.'^'], $repository)));
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));

        $amended = $run->fresh();
        // AC-08: only run_base_sha moves; the approval provenance never does.
        self::assertSame($commitOid, $amended->run_base_sha);
        self::assertSame($initialBase, $amended->initial_run_base_sha);
        self::assertNotSame($parentOid, $amended->run_base_sha);
        self::assertSame($approvedControlBefore, TicketApproval::query()
            ->findOrFail($amended->ticket_approval_id)->approved_control_sha);
        // finalizeAmendedRun rebound ticket blob, contract hash, scope and
        // prompt; the effective project configuration stayed byte-stable
        // because only the ticket file changed.
        self::assertSame($mutation->expected_target_blob_sha, $amended->ticket_blob_sha);
        self::assertSame($mutation->target_contract_sha256, $amended->ticket_contract_sha256);
        self::assertSame($configHashBefore, (string) $amended->config_hash);
        self::assertSame($scopeHashBefore, (string) $amended->scope_hash);
        self::assertSame(1, $amended->evidence_epoch);
        // The ticket status never moved through the amendment.
        self::assertStringContainsString(
            'status: in_progress',
            TicketReadModel::query()->where('relative_path', $bound['path'])->firstOrFail()->redacted_content,
        );
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
    }

    public function test_a_compare_and_swap_conflict_parks_the_run_and_publishes_nothing(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real contract-amendment worker proof requires the POSIX process and effect-lock runtime.');
        }

        $bound = $this->amendableManagedRun('AI6-AMEND-CAS');
        $fixture = $bound['fixture'];
        $run = $bound['run'];
        $parentOid = $run->run_base_sha;
        $target = str_replace('Gebundener Runstart.', 'Konfliktziel.', $bound['content']);

        $operation = $this->app->make(QueueContractAmendment::class)->handle(
            $bound['actor'],
            $fixture['project']->refresh(),
            $run,
            $bound['readModel'],
            (string) Str::uuid(),
            $target,
            'Vertragsanpassung mit Konflikt',
            true,
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('7', 32));
        self::assertIsInt($attemptToken);
        $executor = $this->app->make(TicketMutationExecutor::class);
        self::assertFalse($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);

        // A foreign control commit lands before the publish; the compare-and-swap
        // must refuse instead of rebasing (AC-13).
        $this->managedFixtureGit(['fetch', $fixture['remote'], 'refs/heads/main'], $fixture['source']);
        $this->managedFixtureGit(['reset', '--hard', 'FETCH_HEAD'], $fixture['source']);
        self::assertNotFalse(file_put_contents($fixture['source'].'/FOREIGN.md', "Fremder Fortschritt.\n"));
        $this->managedFixtureGit(['add', 'FOREIGN.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'foreign control progress'], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
        $foreignHead = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        self::assertSame(ControlOperationState::FAILED, $operation->state);
        self::assertSame(
            ControlOperationOutcome::FAILED,
            ControlOperationResult::query()->where('control_operation_id', $operation->id)->firstOrFail()->outcome,
        );
        // Nothing was published and no rebase happened.
        self::assertSame($foreignHead, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));

        // The run survives behind git_base_changed and keeps the project lock
        // through parkAmendedRunOnConflict wired into ControlOperationExecutor.
        $parked = $run->fresh();
        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $parked->wait_reason);
        self::assertSame($parentOid, $parked->run_base_sha);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        self::assertSame(1, ControlOperation::query()
            ->where('operation_type', ControlOperationType::CONTRACT_AMENDMENT)->count());
    }

    /**
     * F-9: the queue check alone does not close the race — a planned step can
     * be claimed after it passed. The executor rechecks under the operation
     * lock and before any external effect.
     */
    public function test_the_executor_rechecks_the_running_step_under_the_lock(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real contract-amendment worker proof requires the POSIX process and effect-lock runtime.');
        }

        $bound = $this->amendableManagedRun('AI6-AMEND-RACE');
        $fixture = $bound['fixture'];
        $run = $bound['run'];
        $parentOid = $run->run_base_sha;
        $target = str_replace('Gebundener Runstart.', 'Rennenziel.', $bound['content']);

        $operation = $this->app->make(QueueContractAmendment::class)->handle(
            $bound['actor'],
            $fixture['project']->refresh(),
            $run,
            $bound['readModel'],
            (string) Str::uuid(),
            $target,
            'Vertragsanpassung im Rennen',
            true,
        );

        // The step is claimed only after the queue check has passed.
        ExecutionJob::query()->where('run_id', $run->getKey())->update(['state' => ExecutionJobState::RUNNING]);
        self::assertTrue(ExecutionJob::query()->where('run_id', $run->getKey())
            ->where('state', ExecutionJobState::RUNNING)->exists());

        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('3', 32));
        self::assertIsInt($attemptToken);
        try {
            $this->app->make(TicketMutationExecutor::class)->advance($operation, $attemptToken);
            self::fail('The amendment must not proceed while an implement step runs.');
        } catch (ControlOperationTerminalConflict $conflict) {
            self::assertSame('amendment_step_running', $conflict->conflict);
        }

        // No external effect: no prepared commit, unchanged control head and run base.
        self::assertNull(TicketMutation::query()->findOrFail($operation->id)->prepared_commit_oid);
        self::assertSame($parentOid, $run->fresh()->run_base_sha);
        self::assertSame($parentOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
    }

    /**
     * N2: a path this run rejected must not re-enter the effective scope
     * through the ticket files list — refused again under the lock when the
     * rejection lands after the amendment was queued.
     */
    public function test_the_executor_refuses_a_readded_rejected_path(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real contract-amendment worker proof requires the POSIX process and effect-lock runtime.');
        }

        $bound = $this->amendableManagedRun('AI6-AMEND-READD');
        $fixture = $bound['fixture'];
        $run = $bound['run'];
        $target = str_replace(
            'depends_on: []
',
            'depends_on: []
files:
  - docs/rejected.md
',
            $bound['content'],
        );
        self::assertNotSame($bound['content'], $target);

        $operation = $this->app->make(QueueContractAmendment::class)->handle(
            $bound['actor'],
            $fixture['project']->refresh(),
            $run,
            $bound['readModel'],
            (string) Str::uuid(),
            $target,
            'Vertragsanpassung mit abgelehntem Pfad',
            true,
        );

        // The rejection lands only after the amendment was queued.
        $this->app->make(RunOrchestrator::class)->applyScopeDecision(
            $run->fresh(),
            'docs/rejected.md',
            false,
            null,
            12,
            $this->app->make(CanonicalJson::class),
            'human_rejected',
        );

        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('4', 32));
        self::assertIsInt($attemptToken);
        try {
            $this->app->make(TicketMutationExecutor::class)->advance($operation, $attemptToken);
            self::fail('The executor must refuse a re-added rejected path.');
        } catch (ControlOperationTerminalConflict $conflict) {
            self::assertSame('amendment_readds_rejected_path', $conflict->conflict);
        }
        self::assertNull(TicketMutation::query()->findOrFail($operation->id)->prepared_commit_oid);
        self::assertSame('rejected', ScopeDecision::query()
            ->where('run_id', $run->getKey())->where('path', 'docs/rejected.md')->firstOrFail()->outcome);
    }

    private function amendmentApprovalSelection(): ApprovalSelection
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
            null,
            'manual',
        );
    }
}
