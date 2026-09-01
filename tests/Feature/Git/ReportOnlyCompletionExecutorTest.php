<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RecordedScopeRenderer;
use App\AI6\Runs\ReportOnlyCompletionService;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use App\AI6\Tickets\TicketContentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

/** TC-01/TC-02: real report-only status mutation, restart and CAS proof. */
final class ReportOnlyCompletionExecutorTest extends TicketUiTestCase
{
    use BuildsManagedControlRuntimeFixture;

    public function test_published_implementation_status_saga_reaches_review_and_releases_the_run_lock(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The publish completion worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->publishedImplementationManagedRun($fixture, 'AI6-PUBLISH-COMPLETE');
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->pending_status_operation_id);
        $mutation = TicketMutation::query()->findOrFail($operation->id);

        self::assertSame('in_progress', $mutation->source_status);
        self::assertSame('review', $mutation->target_status);
        self::assertStringContainsString('## Recorded Scope', $mutation->target_content);
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $completed = $run->fresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->fresh()->state);
        self::assertSame(RunState::COMPLETED, $completed->state);
        self::assertNull($fixture['project']->fresh()->active_run_id);
        self::assertSame($mutation->prepared_commit_oid, $completed->run_base_sha);
        self::assertStringContainsString('status: review', $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $completed->run_base_sha.':'.$bound['relativePath'],
        ], $fixture['root']));
        self::assertStringContainsString('## Recorded Scope', $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $completed->run_base_sha.':'.$bound['relativePath'],
        ], $fixture['root']));
    }

    public function test_report_only_completion_survives_a_crash_at_every_phase_and_releases_only_after_finalization(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The report-only completion worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->completionReadyManagedRun($fixture, 'AI6-REPORT-COMPLETE');
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->pending_status_operation_id);
        self::assertSame('ready', TicketMutation::query()->findOrFail($operation->id)->target_status);
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
            if ($from !== ControlOperationPhase::PREPARED) {
                self::assertTrue($lease->expire($operation->id, $operation->project_id, $attemptToken));
                $attemptToken = $lease->claim($operation->refresh(), str_repeat($bootSeed, 32));
                self::assertIsInt($attemptToken);
            }

            $this->installProgressCrash($operation->id, $from, $to);
            try {
                $executor->advance($operation->refresh(), $attemptToken);
                self::fail('The report-only crash injection did not interrupt '.$from->value.'.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('synthetic report completion crash', $exception->getMessage());
            } finally {
                $this->removeProgressCrash();
            }
            self::assertSame($from, $operation->refresh()->phase);
            self::assertSame(RunState::WAITING, $run->fresh()->state);
            self::assertSame(WaitReason::STATUS_SYNC, $run->fresh()->wait_reason);
            self::assertSame($operation->id, $run->fresh()->pending_status_operation_id);
            self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);

            $completed = $executor->advance($operation->refresh(), $attemptToken);
            self::assertSame($to === ControlOperationPhase::DB_FINALIZED, $completed);
            self::assertSame($to, $operation->refresh()->phase);
        }

        $mutation = TicketMutation::query()->findOrFail($operation->id);
        $commitOid = (string) $mutation->prepared_commit_oid;
        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
        self::assertSame(RunState::COMPLETED, $run->fresh()->state);
        self::assertNull($fixture['project']->fresh()->active_run_id);
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($headBefore, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', $commitOid.'^',
        ], $fixture['root'])));
        self::assertStringContainsString('status: ready', $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $commitOid.':'.$bound['relativePath'],
        ], $fixture['root']));

        $version = $run->fresh()->version;
        self::assertTrue($executor->advance($operation->refresh(), $attemptToken));
        self::assertSame($version, $run->fresh()->version);

        self::assertSame('consumed', TicketApproval::query()->findOrFail($run->ticket_approval_id)->queue_state);
        try {
            $this->app->make(QueueRunStart::class)->handle(
                $bound['operator'],
                $fixture['project']->fresh(),
                $run->ticket_approval_id,
                (string) Str::uuid(),
            );
            self::fail('The consumed approval started a second run lineage.');
        } catch (ControlOperationConflict $conflict) {
            self::assertSame('The approval is not startable.', $conflict->getMessage());
        }
        self::assertSame(1, Run::query()->count());
    }

    public function test_reconciler_redelivers_an_orphaned_report_status_operation_to_completion(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The report-only reconciler proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->completionReadyManagedRun($fixture, 'AI6-REPORT-RECONCILE');
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->pending_status_operation_id);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'updated_at' => now()->subHour(),
        ]);

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->where('payload', 'like', '%'.$operation->id.'%')->count());
        DB::table('jobs')->delete();
        (new ExecuteControlOperation($operation->id))->handle($this->app->make(ControlOperationExecutor::class));

        self::assertSame(ControlOperationState::COMPLETED, $operation->fresh()->state);
        self::assertSame(RunState::COMPLETED, $run->fresh()->state);
        self::assertNull($fixture['project']->fresh()->active_run_id);
    }

    #[DataProvider('foreignCommitCases')]
    public function test_foreign_control_commit_parks_report_completion_without_adoption_or_lock_release(
        bool $changesTicketToReady,
        string $ticketSuffix,
        string $expectedForeignStatus,
    ): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The report-only completion conflict proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $bound = $this->completionReadyManagedRun($fixture, 'AI6-REPORT-CONFLICT-'.$ticketSuffix);
        $run = $bound['run'];
        $operation = ControlOperation::query()->findOrFail($run->pending_status_operation_id);
        $executor = $this->app->make(TicketMutationExecutor::class);
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('a', 32));
        self::assertIsInt($attemptToken);
        self::assertFalse($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);
        $preparedCommit = TicketMutation::query()->findOrFail($operation->id)->prepared_commit_oid;
        self::assertIsString($preparedCommit);

        $this->managedFixtureGit(['fetch', $fixture['remote'], 'refs/heads/main'], $fixture['source']);
        $this->managedFixtureGit(['reset', '--hard', 'FETCH_HEAD'], $fixture['source']);
        if ($changesTicketToReady) {
            $ticketPath = $fixture['source'].'/'.$bound['relativePath'];
            $ticket = file_get_contents($ticketPath);
            self::assertIsString($ticket);
            $ready = str_replace('status: in_progress', 'status: ready', $ticket, $replacements);
            self::assertSame(1, $replacements);
            self::assertNotFalse(file_put_contents($ticketPath, $ready));
            $this->managedFixtureGit(['add', $bound['relativePath']], $fixture['source']);
        } else {
            self::assertNotFalse(file_put_contents($fixture['source'].'/NOTES.md', "Fremder Statuseintrag.\n"));
            $this->managedFixtureGit(['add', 'NOTES.md'], $fixture['source']);
        }
        $this->managedFixtureGit(['commit', '-m', implode("\n", [
            'AI6 ticket mutation',
            '',
            'AI6-Operation-ID: '.Str::uuid(),
            'AI6-Reason: Fremde Statusmutation',
        ])], $fixture['source']);
        $foreignCommit = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        self::assertNotSame($preparedCommit, $foreignCommit);
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        self::assertSame(ControlOperationState::FAILED, $operation->refresh()->state);
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
        self::assertStringContainsString('status: '.$expectedForeignStatus, $this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'show', $foreignCommit.':'.$bound['relativePath'],
        ], $fixture['root']));
        self::assertNull($this->app->make(ReportOnlyCompletionService::class)->reconcileOperation($operation->fresh()));
        self::assertSame(RunState::WAITING, $run->fresh()->state);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        $request = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame(WaitReason::GIT_CONFLICT->value, $request->kind);
        self::assertSame(['refresh_expected_oid'], $request->allowed_effects);

        $version = $parked->version;
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        self::assertSame($version, $run->fresh()->version);
        self::assertSame(1, HumanRequest::query()->where('run_id', $run->id)->count());
        self::assertSame($foreignCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main',
        ], $fixture['root'])));
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function foreignCommitCases(): iterable
    {
        yield 'unrelated foreign commit' => [false, 'UNRELATED', 'in_progress'];
        yield 'foreign same-target ready commit with mutation trailers' => [true, 'READY', 'ready'];
    }

    /**
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
     * @return array{run: Run, relativePath: string, operator: User}
     */
    private function completionReadyManagedRun(array $fixture, string $ticketId): array
    {
        config(['queue.default' => 'database']);
        $relativePath = 'tickets/'.$ticketId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Gebundener Berichtabschluss.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $todo));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add report-only fixture'], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'], $fixture['project']->refresh(), ControlOperationType::MANAGED_CLONE, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);
        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'], $fixture['project']->refresh(), $relativePath, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);

        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $this->addMembership($operator, $fixture['project'], ProjectRole::OPERATOR);
        $readModel = TicketReadModel::query()->where('relative_path', $relativePath)->firstOrFail();
        $selection = $this->approvalSelection((int) $operator->id);
        $approvalId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $fixture['project']->refresh(), $readModel, $selection, $approvalId,
        );
        $approvalOperation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver, $fixture['project']->refresh(), $readModel, $approvalId,
            $readModel->control_commit, $readModel->blob_sha, $todo,
            'Report-only Fixture freigeben', true, $selection, $snapshot, $approvalId,
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($approvalOperation->id);

        $start = $this->app->make(QueueRunStart::class)->handle(
            $operator, $fixture['project']->refresh(), $approvalId, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($start->id);
        $run = Run::query()->sole();
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $run = $orchestrator->advancePhase($run, $run->version, RunPhase::REVIEW);
        $run = $orchestrator->bindCheckpoint(
            $run, $run->version, str_repeat('6', 64), str_repeat('7', 64), str_repeat('8', 64),
        );
        $orchestrator->materializeReviewSlots($run);
        $this->storeReviewResult($run, $snapshot->aggregateHash);
        $run = $this->app->make(ReportOnlyCompletionService::class)->start($run->fresh());
        DB::table('jobs')->delete();

        return ['run' => $run, 'relativePath' => $relativePath, 'operator' => $operator];
    }

    /**
     * @param  array{administrator: User, project: Project, root: string, remote: string, source: string, first_oid: string, paths: ManagedProjectPath, runner: HardenedGitRunner, lease: ProjectOperationLease}  $fixture
     * @return array{run: Run, relativePath: string}
     */
    private function publishedImplementationManagedRun(array $fixture, string $ticketId): array
    {
        config(['queue.default' => 'database']);
        $relativePath = 'tickets/'.$ticketId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Gebundener Publishabschluss.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $todo));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add publish completion fixture'], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'], $fixture['project']->refresh(), ControlOperationType::MANAGED_CLONE, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);
        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'], $fixture['project']->refresh(), $relativePath, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);

        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $this->addMembership($operator, $fixture['project'], ProjectRole::OPERATOR);
        $readModel = TicketReadModel::query()->where('relative_path', $relativePath)->firstOrFail();
        $selection = $this->implementationApprovalSelection((int) $approver->id);
        $approvalId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $fixture['project']->refresh(), $readModel, $selection, $approvalId,
        );
        $approvalOperation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver, $fixture['project']->refresh(), $readModel, $approvalId,
            $readModel->control_commit, $readModel->blob_sha, $todo,
            'Implementierungsfixture freigeben', true, $selection, $snapshot, $approvalId,
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($approvalOperation->id);
        $start = $this->app->make(QueueRunStart::class)->handle(
            $operator, $fixture['project']->refresh(), $approvalId, (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($start->id);

        $run = Run::query()->sole();
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $run = $orchestrator->bindCheckpoint(
            $run, $run->version, str_repeat('6', 64), str_repeat('7', 64), str_repeat('8', 64),
        );
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        Run::query()->whereKey($run->id)->update([
            'phase' => RunPhase::PUBLISH->value,
            'candidate_tree_sha' => str_repeat('a', 64),
            'candidate_diff_hash' => str_repeat('b', 64),
            'candidate_base_sha' => $run->run_base_sha,
            'candidate_checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'candidate_ticket_contract_sha256' => $approval->ticket_contract_sha256,
            'candidate_approval_snapshot_hash' => $snapshot->aggregateHash,
            'candidate_evidence_epoch' => $run->evidence_epoch,
            'candidate_scope_hash' => $run->effective_scope_hash ?? $run->scope_hash,
            'candidate_config_hash' => $run->config_hash,
            'candidate_prompt_hash' => $run->prompt_hash,
            'candidate_security_policy_hash' => $run->security_policy_hash,
            'candidate_bound_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        $run = $run->fresh();
        self::assertInstanceOf(Run::class, $run);
        $run = $orchestrator->prepareBranchPublication($run, $run->version, str_repeat('0', 64));
        $run = $orchestrator->bindPublishIntent($run, $run->version, str_repeat('d', 64), str_repeat('0', 64), true);
        $run = $orchestrator->confirmBranchPublication($run, $run->version, str_repeat('d', 64));

        $project = $fixture['project']->refresh();
        $readModel = TicketReadModel::query()->where('project_id', $project->id)
            ->where('relative_path', $relativePath)->firstOrFail();
        $target = $this->app->make(TicketContentStatus::class)->replace(
            $this->app->make(RecordedScopeRenderer::class)->write($run, $readModel->redacted_content),
            'in_progress',
            'review',
        );
        $operation = $this->app->make(QueueTicketMutation::class)->completeImplementationRun(
            $approver, $project, $readModel, $run, (string) Str::uuid(), $target,
            'Gebundener Publishabschluss mit Recorded Scope.',
        );
        $run = $orchestrator->bindPublishCompletionOperation($run, $run->version, $operation, hash('sha256', $target));
        DB::table('jobs')->delete();

        return ['run' => $run, 'relativePath' => $relativePath];
    }

    private function implementationApprovalSelection(int $attentionUserId): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model',
                'effort' => 'high', 'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attentionUserId, 'automatic_after_gates', RunType::IMPLEMENTATION,
        );
    }

    private function approvalSelection(int $attentionUserId): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model',
                'effort' => 'high', 'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attentionUserId, 'manual', RunType::REVIEW_ONLY, 'checkpoint:server-bound',
            ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES,
        );
    }

    private function storeReviewResult(Run $run, string $approvalSnapshotHash): void
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $slot = $run->agents()->where('role', 'quality_review')->firstOrFail();
        $slot = $orchestrator->bindReviewSession($run, $slot->slot_id, (string) Str::uuid());
        $artifact = RunArtifact::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'kind' => 'provider_raw',
            'redacted_metadata' => [], 'digest' => str_repeat('9', 64), 'size_bytes' => 2,
            'sequence' => 1, 'storage_reference' => 'test://report-only-result/'.Str::uuid(),
            'expires_at' => now()->addDay(),
        ]);
        ReviewResult::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'round_number' => 1,
            'slot_id' => $slot->slot_id, 'attempt' => 1, 'role' => 'quality_review',
            'provider_profile' => $slot->provider_profile, 'model' => $slot->model,
            'effort' => $slot->effort, 'prompt_profile' => $slot->prompt_profile,
            'session_id' => $slot->session_id, 'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha, 'diff_hash' => $run->checkpoint_diff_hash,
            'approval_config_hash' => $run->config_hash, 'approval_scope_hash' => $run->scope_hash,
            'approval_prompt_hash' => $run->prompt_hash, 'approval_instruction_hash' => $run->instruction_hash,
            'approval_runtime_profile_hash' => $run->runtime_profile_hash,
            'approval_agent_profile_hash' => $run->agent_profile_hash,
            'approval_security_policy_hash' => $run->security_policy_hash,
            'approval_snapshot_hash' => $approvalSnapshotHash, 'workspace_tree_hash' => $run->checkpoint_tree_sha,
            'invocation_outcome' => ReviewInvocationOutcome::VALID_RESULT, 'result_status' => 'nothing_to_fix',
            'raw_artifact_id' => $artifact->id,
        ]);
    }

    private function installProgressCrash(
        string $operationId,
        ControlOperationPhase $from,
        ControlOperationPhase $to,
    ): void {
        self::assertTrue(ManagedProjectPath::validOperationIdentifier($operationId));
        DB::unprepared(sprintf(
            "CREATE TEMP TRIGGER report_completion_progress_crash BEFORE UPDATE ON control_operations WHEN OLD.id = '%s' AND OLD.phase = '%s' AND NEW.phase = '%s' BEGIN SELECT RAISE(ABORT, 'synthetic report completion crash'); END",
            $operationId, $from->value, $to->value,
        ));
    }

    private function removeProgressCrash(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS report_completion_progress_crash');
    }
}
