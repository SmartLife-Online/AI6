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
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketStatusOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

final class TicketMutationExecutorTest extends TicketUiTestCase
{
    use BuildsManagedControlRuntimeFixture;

    #[DataProvider('mutationKinds')]
    public function test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection(string $kind): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real ticket-mutation worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $actor = $fixture['administrator'];
        if ($kind === 'approval') {
            $actor = $this->createUser();
            $this->addMembership($actor, $fixture['project'], ProjectRole::APPROVER);
        }
        $sourceStatus = $kind === 'edit' ? 'ready' : 'todo';
        $sourceContent = $this->validTicketMarkdown('AI6-909', $sourceStatus, '[]', 'Alter Inhalt.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/tickets/AI6-909.md', $sourceContent));
        $this->managedFixtureGit(['add', 'tickets/AI6-909.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add mutation fixture'], $fixture['source']);
        $parentOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);
        self::assertSame($parentOid, $fixture['project']->refresh()->control_oid);

        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            'tickets/AI6-909.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);
        $readModel = TicketReadModel::query()->where('relative_path', 'tickets/AI6-909.md')->firstOrFail();
        // refresh() below re-hydrates this same instance with the published
        // target state; the reviewed source blob must be bound before that.
        $reviewedSourceBlob = $readModel->blob_sha;
        $targetContent = match ($kind) {
            'edit' => str_replace('Alter Inhalt.', 'Neuer Inhalt.', $sourceContent),
            'approval' => str_replace('status: todo', 'status: ready', $sourceContent),
            default => str_replace('status: todo', 'status: blocked', $sourceContent),
        };
        $queue = $this->app->make(QueueTicketMutation::class);
        $operationId = (string) Str::uuid();
        $approvalSelection = $kind === 'approval' ? $this->approvalSelection() : null;
        $approvalSnapshot = $approvalSelection === null
            ? null
            : $this->app->make(ApprovalSnapshotFactory::class)->create($fixture['project']->refresh(), $readModel, $approvalSelection, $operationId);
        $operation = match ($kind) {
            'edit' => $queue->edit(
                $actor, $fixture['project']->refresh(), $readModel, $operationId,
                $readModel->control_commit, $readModel->blob_sha, $sourceContent, $targetContent,
                'Fachliche Korrektur', true,
            ),
            'approval' => $queue->approve(
                $actor, $fixture['project']->refresh(), $readModel, $operationId,
                $readModel->control_commit, $readModel->blob_sha, $sourceContent,
                'Menschliche Freigabe', true, $approvalSelection, $approvalSnapshot, $operationId,
            ),
            default => $queue->changeStatus(
                $actor, $fixture['project']->refresh(), $readModel, $operationId,
                $readModel->control_commit, $readModel->blob_sha, $sourceContent,
                'Fachliche Blockade', true, TicketStatusOperation::BLOCK, false,
            ),
        };
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('e', 32));
        self::assertIsInt($attemptToken);
        $executor = $this->app->make(TicketMutationExecutor::class);

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $attemptToken = $fixture['lease']->claim($operation->refresh(), str_repeat('f', 32));
        self::assertIsInt($attemptToken);

        if ($kind === 'approval') {
            self::assertSame(1, TicketApproval::query()->whereKey($operation->id)->count());
            self::assertSame('prepared', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
            $this->installApprovalProgressCrash(
                $operation->id,
                ControlOperationPhase::PREPARED,
                ControlOperationPhase::COMMIT_PREPARED,
            );
            try {
                $executor->advance($operation, $attemptToken);
                self::fail('The prepared-phase crash injection did not interrupt operation progress.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('synthetic ticket mutation progress crash', $exception->getMessage());
            } finally {
                $this->removeApprovalProgressCrash();
            }
            self::assertSame(ControlOperationPhase::PREPARED, $operation->refresh()->phase);
            self::assertSame('commit_prepared', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
        }
        self::assertFalse($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);
        if ($kind === 'approval') {
            self::assertSame(1, TicketApproval::query()->whereKey($operation->id)->count());
            self::assertSame('commit_prepared', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
        }

        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertNotNull($mutation->prepared_commit_oid);
        $commitOid = $mutation->prepared_commit_oid;
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier),
        );
        $projectBeforePublish = $fixture['project']->refresh();
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $publishContext = new RedactionContext((string) $projectBeforePublish->getKey(), $operation->id, 'ticket-mutation-crash-after-push');
        self::assertTrue($fixture['runner']->pushCommitCas(
            $repository,
            (string) $projectBeforePublish->remote,
            (string) $projectBeforePublish->control_branch,
            (string) $operation->expected_control_commit,
            $commitOid,
            (string) $projectBeforePublish->deploy_key_reference,
            $configuration->knownHostsFile,
            (string) $projectBeforePublish->host_key_fingerprint,
            $publishContext,
        )->succeeded());

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $attemptToken = $fixture['lease']->claim($operation->refresh(), str_repeat('1', 32));
        self::assertIsInt($attemptToken);
        if ($kind === 'approval') {
            $this->installApprovalProgressCrash(
                $operation->id,
                ControlOperationPhase::COMMIT_PREPARED,
                ControlOperationPhase::CONTROL_CONFIRMED,
            );
            try {
                $executor->advance($operation, $attemptToken);
                self::fail('The control-confirmation crash injection did not interrupt operation progress.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('synthetic ticket mutation progress crash', $exception->getMessage());
            } finally {
                $this->removeApprovalProgressCrash();
            }
            self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);
            self::assertSame('control_confirmed', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
        }
        self::assertFalse($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::CONTROL_CONFIRMED, $operation->refresh()->phase);
        if ($kind === 'approval') {
            self::assertSame(1, TicketApproval::query()->whereKey($operation->id)->count());
            self::assertSame('control_confirmed', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
        }

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $attemptToken = $fixture['lease']->claim($operation->refresh(), str_repeat('2', 32));
        self::assertIsInt($attemptToken);
        self::assertTrue($executor->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::DB_FINALIZED, $operation->refresh()->phase);
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertTrue($executor->advance($operation, $attemptToken));
        if ($kind === 'approval') {
            self::assertSame(1, TicketApproval::query()->whereKey($operation->id)->count());
            self::assertSame('complete', TicketApproval::query()->findOrFail($operation->id)->saga_phase);
        }

        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($commitOid, trim($this->managedFixtureGit(['rev-parse', 'refs/heads/main'], $repository)));
        self::assertSame(
            ['tickets/AI6-909.md'],
            preg_split('/\r?\n/', trim($this->managedFixtureGit([
                'diff-tree', '--no-commit-id', '--name-only', '-r', $commitOid,
            ], $repository))),
        );
        self::assertSame($parentOid, trim($this->managedFixtureGit(['rev-parse', $commitOid.'^'], $repository)));
        self::assertSame(
            [$commitOid, $parentOid],
            preg_split('/\s+/', trim($this->managedFixtureGit(['rev-list', '--parents', '-n', '1', $commitOid], $repository))),
        );
        $message = $this->managedFixtureGit(['show', '-s', '--format=%B', $commitOid], $repository);
        self::assertStringContainsString('AI6-Actor-ID: '.$actor->getKey(), $message);
        self::assertStringContainsString('AI6-Reason: '.match ($kind) {
            'edit' => 'Fachliche Korrektur',
            'approval' => 'Menschliche Freigabe',
            default => 'Fachliche Blockade',
        }, $message);
        self::assertStringContainsString('AI6-Previous-Blob: '.$readModel->blob_sha, $message);

        try {
            $queue->edit(
                $fixture['administrator'],
                $fixture['project']->refresh(),
                $readModel,
                (string) Str::uuid(),
                $parentOid,
                $readModel->blob_sha,
                $sourceContent,
                str_replace('Alter Inhalt.', 'Konkurrierender Inhalt.', $sourceContent),
                'Konkurrierender Edit',
                true,
            );
            self::fail('A second editor overwrote the completed mutation.');
        } catch (TicketMutationConflict $exception) {
            self::assertContains($exception->conflict, ['editor_unavailable', 'editor_base_binding_changed', 'mutation_binding_changed']);
        }

        $project = $fixture['project']->refresh();
        $fresh = $readModel->refresh();
        self::assertSame($commitOid, $project->control_oid);
        self::assertSame(2, $project->control_binding_version);
        self::assertSame(0, $project->control_generation);
        self::assertSame($commitOid, $fresh->control_commit);
        self::assertSame($mutation->expected_target_blob_sha, $fresh->blob_sha);
        self::assertStringContainsString($kind === 'edit' ? 'Neuer Inhalt.' : 'Alter Inhalt.', $fresh->redacted_content);
        self::assertStringContainsString('status: '.match ($kind) {
            'approval' => 'ready',
            'status' => 'blocked',
            default => 'todo',
        }, $fresh->redacted_content);
        if ($kind === 'approval') {
            $approval = TicketApproval::query()->findOrFail($operation->id);
            self::assertSame('complete', $approval->saga_phase);
            self::assertSame('queued', $approval->queue_state);
            self::assertSame($reviewedSourceBlob, $approval->reviewed_ticket_blob_sha);
            self::assertSame($fresh->blob_sha, $approval->approved_ticket_blob_sha);
            self::assertSame($commitOid, $approval->approved_control_sha);
        }

        $attemptRef = ManagedProjectPath::attemptRef($operation->id, $attemptToken);
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'terminal-cleanup-test');
        self::assertTrue($fixture['runner']->createAttemptRef($repository, $attemptRef, $commitOid, $context)->succeeded());
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        $missingAttempt = $fixture['runner']->resolveAttemptRef($repository, $attemptRef, $context);
        self::assertFalse($missingAttempt->succeeded());
        self::assertSame(1, $missingAttempt->exitCode);
    }

    /** @return iterable<string, array{string}> */
    public static function mutationKinds(): iterable
    {
        yield 'ticket edit' => ['edit'];
        yield 'ticket status change' => ['status'];
        yield 'ticket approval' => ['approval'];
    }

    #[DataProvider('runStartLineageCases')]
    public function test_run_start_lineage_crash_replay_and_foreign_commit_contract(string $change): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real run-start Git and crash proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $relativePath = 'tickets/AI6-RUN-START.md';
        $todo = $this->validTicketMarkdown('AI6-RUN-START', 'todo', '[]', 'Gebundener Runstart.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $todo));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add run-start fixture'], $fixture['source']);
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
        $selection = $this->approvalSelection();
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
        $approval = TicketApproval::query()->findOrFail($approvalId);
        self::assertSame('complete', $approval->saga_phase);
        self::assertSame('queued', $approval->queue_state);
        $approvedControl = $approval->approved_control_sha;
        self::assertIsString($approvedControl);

        $this->managedFixtureGit(['fetch', $fixture['remote'], 'refs/heads/main'], $fixture['source']);
        $this->managedFixtureGit(['reset', '--hard', 'FETCH_HEAD'], $fixture['source']);
        if ($change === 'irrelevant') {
            self::assertNotFalse(file_put_contents($fixture['source'].'/IRRELEVANT.md', "Irrelevanter Fortschritt.\n"));
            $this->managedFixtureGit(['add', 'IRRELEVANT.md'], $fixture['source']);
            $this->managedFixtureGit(['commit', '-m', 'add irrelevant control progress'], $fixture['source']);
            $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
        } elseif ($change === 'relevant') {
            $ready = (string) file_get_contents($fixture['source'].'/'.$relativePath);
            self::assertNotFalse(file_put_contents(
                $fixture['source'].'/'.$relativePath,
                str_replace('Gebundener Runstart.', 'Geänderter Runstart.', $ready),
            ));
            $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
            $this->managedFixtureGit(['commit', '-m', 'change approved ticket'], $fixture['source']);
            $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
        } elseif ($change === 'rewrite') {
            $tree = trim($this->managedFixtureGit(['rev-parse', 'FETCH_HEAD^{tree}'], $fixture['source']));
            $unrelated = trim($this->managedFixtureGit(['commit-tree', $tree, '-m', 'unrelated identical tree'], $fixture['source']));
            $this->managedFixtureGit(['push', '--force', $fixture['remote'], $unrelated.':refs/heads/main'], $fixture['source']);
        }

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
            $relativePath,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);

        try {
            $runStart = $this->app->make(QueueRunStart::class)->handle(
                $operator,
                $fixture['project']->refresh(),
                $approvalId,
                (string) Str::uuid(),
            );
        } catch (ControlOperationConflict $conflict) {
            self::assertSame('relevant', $change);
            self::assertSame(0, Run::query()->count());
            self::assertNull($fixture['project']->fresh()->active_run_id);

            return;
        }
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($runStart, str_repeat('9', 32));
        self::assertIsInt($attemptToken);
        $executor = $this->app->make(TicketMutationExecutor::class);

        if ($change === 'foreign') {
            self::assertFalse($executor->advance($runStart, $attemptToken));
            $runStart->refresh();
            self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $runStart->phase);
            $mutation = TicketMutation::query()->findOrFail($runStart->id);
            self::assertIsString($mutation->prepared_commit_oid);
            self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $mutation->target_content));
            $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
            $this->managedFixtureGit(['commit', '-m', 'foreign matching in-progress transition'], $fixture['source']);
            $foreignCommit = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
            self::assertNotSame($mutation->prepared_commit_oid, $foreignCommit);
            self::assertSame(
                $mutation->expected_target_tree_oid,
                trim($this->managedFixtureGit(['rev-parse', $foreignCommit.'^{tree}'], $fixture['source'])),
            );
            $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
            self::assertTrue($fixture['lease']->expire($runStart->id, $runStart->project_id, $attemptToken));
            $this->app->make(ControlOperationExecutor::class)->execute($runStart->id);

            $runStart->refresh();
            $result = ControlOperationResult::query()->where('control_operation_id', $runStart->id)->firstOrFail();
            self::assertSame(ControlOperationState::FAILED, $runStart->state);
            self::assertSame(ControlOperationOutcome::FAILED, $result->outcome);
            self::assertSame(
                hash('sha256', "AI6-CONTROL-RESULT-V1\0".$runStart->id.$runStart->request_hash.'control_head_changed'),
                $result->result_binding,
            );
            self::assertSame(0, Run::query()->count());
            self::assertNull($fixture['project']->fresh()->active_run_id);
            self::assertNull($fixture['project']->fresh()->operation_lock_operation_id);

            return;
        }

        if ($change === 'rewrite') {
            try {
                $executor->advance($runStart, $attemptToken);
                self::fail('An unrelated identical ticket history was adopted.');
            } catch (ControlOperationTerminalConflict $conflict) {
                self::assertSame('run_start_lineage_changed', $conflict->conflict);
            }
            self::assertSame(0, Run::query()->count());
            self::assertNull($fixture['project']->fresh()->active_run_id);

            return;
        }

        foreach ([
            [ControlOperationPhase::PREPARED, ControlOperationPhase::COMMIT_PREPARED],
            [ControlOperationPhase::COMMIT_PREPARED, ControlOperationPhase::CONTROL_CONFIRMED],
            [ControlOperationPhase::CONTROL_CONFIRMED, ControlOperationPhase::DB_FINALIZED],
        ] as [$from, $to]) {
            $this->installApprovalProgressCrash($runStart->id, $from, $to);
            try {
                $executor->advance($runStart->refresh(), $attemptToken);
                self::fail('The run-start crash injection did not interrupt '.$from->value.'.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('synthetic ticket mutation progress crash', $exception->getMessage());
            } finally {
                $this->removeApprovalProgressCrash();
            }
            self::assertSame($from, $runStart->refresh()->phase);
            $completed = $executor->advance($runStart->refresh(), $attemptToken);
            self::assertSame($to === ControlOperationPhase::DB_FINALIZED, $completed);
        }

        $run = Run::query()->sole();
        $mutation = TicketMutation::query()->findOrFail($runStart->id);
        self::assertSame($mutation->prepared_commit_oid, $run->initial_run_base_sha);
        self::assertSame($run->initial_run_base_sha, $run->run_base_sha);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        self::assertNull($fixture['project']->fresh()->operation_lock_operation_id);
        self::assertTrue($fixture['runner']->isAncestor(
            $fixture['paths']->assertRepository($fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier)),
            $approvedControl,
            $run->claim_parent_control_sha,
            new RedactionContext((string) $fixture['project']->getKey(), $runStart->id, 'run-start-lineage-proof'),
        ));
        self::assertSame(1, ControlOperation::query()->where('operation_type', ControlOperationType::RUN_START)->count());
    }

    /** @return iterable<string, array{string}> */
    public static function runStartLineageCases(): iterable
    {
        yield 'irrelevant fast forward and crash replay' => ['irrelevant'];
        yield 'relevant ticket change' => ['relevant'];
        yield 'unrelated identical history' => ['rewrite'];
        yield 'foreign matching in-progress commit' => ['foreign'];
    }

    private function installApprovalProgressCrash(
        string $operationId,
        ControlOperationPhase $from,
        ControlOperationPhase $to,
    ): void {
        self::assertTrue(ManagedProjectPath::validOperationIdentifier($operationId));
        DB::unprepared(sprintf(
            "CREATE TEMP TRIGGER ticket_mutation_progress_crash BEFORE UPDATE ON control_operations WHEN OLD.id = '%s' AND OLD.phase = '%s' AND NEW.phase = '%s' BEGIN SELECT RAISE(ABORT, 'synthetic ticket mutation progress crash'); END",
            $operationId,
            $from->value,
            $to->value,
        ));
    }

    private function removeApprovalProgressCrash(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_mutation_progress_crash');
    }

    private function approvalSelection(): ApprovalSelection
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

    #[DataProvider('foreignControlChanges')]
    public function test_foreign_control_changes_end_as_the_named_conflict_without_adoption(string $change): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real ticket-mutation conflict proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $sourceContent = $this->validTicketMarkdown('AI6-911', 'todo', '[]', 'Unveränderter Inhalt.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/tickets/AI6-911.md', $sourceContent));
        $this->managedFixtureGit(['add', 'tickets/AI6-911.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add conflict fixture'], $fixture['source']);
        $parentOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
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
            'tickets/AI6-911.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);
        $readModel = TicketReadModel::query()->where('relative_path', 'tickets/AI6-911.md')->firstOrFail();
        $operationId = (string) Str::uuid();
        $selection = $this->approvalSelection();
        $approvalSnapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $fixture['project']->refresh(),
            $readModel,
            $selection,
            $operationId,
        );
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $fixture['project']->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $sourceContent,
            'Konfliktnachweis',
            true,
            $selection,
            $approvalSnapshot,
            $operationId,
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('4', 32));
        self::assertIsInt($attemptToken);
        self::assertFalse($this->app->make(TicketMutationExecutor::class)->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::COMMIT_PREPARED, $operation->refresh()->phase);
        $preparedCommit = TicketMutation::query()->findOrFail($operation->id)->prepared_commit_oid;
        self::assertNotNull($preparedCommit);

        if ($change === 'history_rewrite') {
            $this->managedFixtureGit(['checkout', '--orphan', 'rewritten'], $fixture['source']);
        }
        $foreignContent = str_replace('status: todo', 'status: ready', $sourceContent);
        self::assertNotFalse(file_put_contents($fixture['source'].'/tickets/AI6-911.md', $foreignContent));
        if ($change === 'additional_tree_change') {
            self::assertNotFalse(file_put_contents($fixture['source'].'/foreign.txt', "fremde Treeänderung\n"));
        }
        $this->managedFixtureGit(['add', '--all'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'foreign status change'], $fixture['source']);
        $foreignCommit = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        self::assertNotSame($preparedCommit, $foreignCommit);
        $this->managedFixtureGit(['push', '--force', $fixture['remote'], 'HEAD:refs/heads/main'], $fixture['source']);

        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $result = ControlOperationResult::query()->where('control_operation_id', $operation->id)->firstOrFail();
        self::assertSame(ControlOperationState::FAILED, $operation->state);
        self::assertSame(ControlOperationOutcome::FAILED, $result->outcome);
        self::assertSame(
            hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'control_head_changed'),
            $result->result_binding,
        );
        self::assertSame($foreignCommit, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($parentOid, $fixture['project']->refresh()->control_oid);
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier),
        );
        self::assertSame($parentOid, trim($this->managedFixtureGit(['rev-parse', 'refs/heads/main'], $repository)));
        $approval = TicketApproval::query()->findOrFail($operation->id);
        self::assertSame('conflict', $approval->saga_phase);
        self::assertSame('cancelled', $approval->queue_state);
        self::assertNull($approval->approved_ticket_blob_sha);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignControlChanges(): iterable
    {
        yield 'foreign similar status commit' => ['similar_status_commit'];
        yield 'history rewrite' => ['history_rewrite'];
        yield 'additional target tree change' => ['additional_tree_change'];
    }

    public function test_precommit_recovery_can_be_abandoned_and_releases_the_project_lock(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real precommit ticket-mutation recovery proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->recoveryMutationFixture('AI6-913');
        $operation = $fixture['operation'];
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertNull($mutation->prepared_commit_oid);
        self::assertNull($operation->target_control_oid);

        $attemptRef = ManagedProjectPath::attemptRef($operation->id, $fixture['attempt_token']);
        self::assertTrue($fixture['runner']->createAttemptRef(
            $fixture['repository'],
            $attemptRef,
            $fixture['parent_oid'],
            $fixture['context'],
        )->succeeded());
        $finding = $fixture['executor']->recoveryFinding(
            $operation,
            $fixture['attempt_token'],
            'Recovery vor Persistenz des Mutationscommits.',
        );
        $recoveryVersion = $operation->version + 1;
        $operation->forceFill([
            'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
            'state' => ControlOperationState::RECOVERY_REQUIRED,
            'finding_text' => $finding['text'],
            'finding_hash' => $finding['hash'],
            'recovery_attempt_token' => $fixture['attempt_token'],
            'recovery_version' => $recoveryVersion,
            'recovery_effect_hash' => $finding['effect_hash'],
            'last_error' => 'Recovery vor Persistenz des Mutationscommits.',
            'version' => $recoveryVersion,
        ])->save();
        $decision = ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $fixture['administrator']->getKey(),
            'decision' => RecoveryDecisionType::ABANDON_OPERATION,
            'expected_attempt_token' => $fixture['attempt_token'],
            'expected_operation_version' => $recoveryVersion,
            'finding_hash' => $finding['hash'],
            'reason' => 'Unveröffentlichten Versuch sicher abbrechen.',
            'bound_evidence' => '{"schema":"test"}',
            'state' => 'pending',
        ]);
        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $fixture['attempt_token']));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project = $fixture['project']->refresh();
        self::assertSame(ControlOperationState::ABANDONED, $operation->state);
        self::assertSame('applied', $decision->refresh()->state);
        self::assertSame(ControlOperationOutcome::ABANDONED, $operation->result()->firstOrFail()->outcome);
        self::assertNull($project->operation_lock_operation_id);
        self::assertSame($fixture['parent_oid'], $project->control_oid);
        self::assertSame($mutation->expected_control_binding_version, $project->control_binding_version);
        self::assertSame($fixture['parent_oid'], trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($fixture['parent_oid'], trim($this->managedFixtureGit([
            'rev-parse',
            'refs/heads/main',
        ], $fixture['repository'])));
        $missingAttempt = $fixture['runner']->resolveAttemptRef(
            $fixture['repository'],
            $attemptRef,
            $fixture['context'],
        );
        self::assertFalse($missingAttempt->succeeded());
        self::assertSame(1, $missingAttempt->exitCode);
    }

    public function test_published_commit_abandonment_refuses_history_rewrite_and_adoption_moves_forward(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real published ticket-mutation recovery proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->recoveryMutationFixture('AI6-914');
        $operation = $fixture['operation'];
        self::assertFalse($fixture['executor']->advance($operation, $fixture['attempt_token']));
        $operation->refresh();
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        $commitOid = $mutation->prepared_commit_oid;
        self::assertNotNull($commitOid);
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        self::assertTrue($fixture['runner']->pushCommitCas(
            $fixture['repository'],
            (string) $fixture['project']->remote,
            (string) $fixture['project']->control_branch,
            $fixture['parent_oid'],
            $commitOid,
            (string) $fixture['project']->deploy_key_reference,
            $configuration->knownHostsFile,
            (string) $fixture['project']->host_key_fingerprint,
            $fixture['context'],
        )->succeeded());

        $finding = $fixture['executor']->recoveryFinding(
            $operation,
            $fixture['attempt_token'],
            'Crash nach bestätigtem Push.',
        );
        $recoveryVersion = $operation->version + 1;
        $operation->forceFill([
            'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
            'state' => ControlOperationState::RECOVERY_REQUIRED,
            'finding_text' => $finding['text'],
            'finding_hash' => $finding['hash'],
            'recovery_attempt_token' => $fixture['attempt_token'],
            'recovery_version' => $recoveryVersion,
            'recovery_effect_hash' => $finding['effect_hash'],
            'last_error' => 'Crash nach bestätigtem Push.',
            'version' => $recoveryVersion,
        ])->save();
        $abandon = ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $fixture['administrator']->getKey(),
            'decision' => RecoveryDecisionType::ABANDON_OPERATION,
            'expected_attempt_token' => $fixture['attempt_token'],
            'expected_operation_version' => $recoveryVersion,
            'finding_hash' => $finding['hash'],
            'reason' => 'Veröffentlichten Versuch abbrechen.',
            'bound_evidence' => '{"schema":"test"}',
            'state' => 'pending',
        ]);
        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $fixture['attempt_token']));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project = $fixture['project']->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame('rejected', $abandon->refresh()->state);
        self::assertSame(
            'Ein veröffentlichter Ticketmutationscommit kann nicht abgebrochen werden, ohne die Control-Historie umzuschreiben; der gebundene Außenstand kann übernommen werden.',
            $operation->last_error,
        );
        self::assertSame($operation->id, $project->operation_lock_operation_id);
        self::assertSame($fixture['parent_oid'], $project->control_oid);
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($fixture['parent_oid'], trim($this->managedFixtureGit([
            'rev-parse',
            'refs/heads/main',
        ], $fixture['repository'])));

        self::assertIsInt($operation->recovery_attempt_token);
        self::assertIsInt($operation->recovery_version);
        self::assertNotNull($operation->finding_hash);
        $adopt = ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $fixture['administrator']->getKey(),
            'decision' => RecoveryDecisionType::ADOPT_EXTERNAL_STATE,
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
            'state' => 'pending',
        ]);
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame('applied', $adopt->refresh()->state);
        self::assertSame($commitOid, $project->control_oid);
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            'rev-parse',
            'refs/heads/main',
        ], $fixture['repository'])));
        self::assertNull($project->operation_lock_operation_id);
    }

    #[DataProvider('ticketMutationRecoveryDecisions')]
    public function test_ticket_mutation_recovery_decisions_reconcile_only_the_bound_commit(
        RecoveryDecisionType $decisionType,
    ): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real ticket-mutation recovery proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $sourceContent = $this->validTicketMarkdown('AI6-912', 'todo', '[]', 'Recovery-Ausgangsstand.');
        self::assertNotFalse(file_put_contents($fixture['source'].'/tickets/AI6-912.md', $sourceContent));
        $this->managedFixtureGit(['add', 'tickets/AI6-912.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add recovery fixture'], $fixture['source']);
        $parentOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
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
            'tickets/AI6-912.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);
        $readModel = TicketReadModel::query()->where('relative_path', 'tickets/AI6-912.md')->firstOrFail();
        $operation = $this->app->make(QueueTicketMutation::class)->changeStatus(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $sourceContent,
            'Recovery-Nachweis',
            true,
            TicketStatusOperation::BLOCK,
            false,
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('5', 32));
        self::assertIsInt($attemptToken);
        $mutationExecutor = $this->app->make(TicketMutationExecutor::class);
        self::assertFalse($mutationExecutor->advance($operation, $attemptToken));
        $operation->refresh();
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        $commitOid = $mutation->prepared_commit_oid;
        self::assertNotNull($commitOid);
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier),
        );
        $project = $fixture['project']->refresh();
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-recovery-test');
        self::assertTrue($fixture['runner']->pushCommitCas(
            $repository,
            (string) $project->remote,
            (string) $project->control_branch,
            $parentOid,
            $commitOid,
            (string) $project->deploy_key_reference,
            $configuration->knownHostsFile,
            (string) $project->host_key_fingerprint,
            $context,
        )->succeeded());

        $finding = $mutationExecutor->recoveryFinding($operation, $attemptToken, 'Crash nach bestätigtem Push.');
        $recoveryVersion = $operation->version + 1;
        $operation->forceFill([
            'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
            'state' => ControlOperationState::RECOVERY_REQUIRED,
            'finding_text' => $finding['text'],
            'finding_hash' => $finding['hash'],
            'recovery_attempt_token' => $attemptToken,
            'recovery_version' => $recoveryVersion,
            'recovery_effect_hash' => $finding['effect_hash'],
            'last_error' => 'Crash nach bestätigtem Push.',
            'version' => $recoveryVersion,
        ])->save();
        $decision = ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $fixture['administrator']->getKey(),
            'decision' => $decisionType,
            'expected_attempt_token' => $attemptToken,
            'expected_operation_version' => $recoveryVersion,
            'finding_hash' => $finding['hash'],
            'reason' => $decisionType === RecoveryDecisionType::ABANDON_OPERATION ? 'Gebundener Abbruch.' : null,
            'bound_evidence' => $decisionType === RecoveryDecisionType::ABANDON_OPERATION ? '{"schema":"test"}' : null,
            'state' => 'pending',
        ]);
        self::assertTrue($fixture['lease']->expire($operation->id, $operation->project_id, $attemptToken));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame('applied', $decision->refresh()->state);
        self::assertSame($commitOid, $project->control_oid);
        self::assertSame($commitOid, trim($this->managedFixtureGit(['rev-parse', 'refs/heads/main'], $repository)));
        self::assertSame($commitOid, trim($this->managedFixtureGit([
            '--git-dir='.$fixture['remote'],
            'rev-parse',
            'refs/heads/main',
        ], $fixture['root'])));
        self::assertSame($commitOid, $readModel->refresh()->control_commit);
        self::assertSame($mutation->expected_target_blob_sha, $readModel->blob_sha);
    }

    /** @return iterable<string, array{RecoveryDecisionType}> */
    public static function ticketMutationRecoveryDecisions(): iterable
    {
        yield 'retry reconciliation' => [RecoveryDecisionType::RETRY_RECONCILIATION];
        yield 'adopt bound external commit' => [RecoveryDecisionType::ADOPT_EXTERNAL_STATE];
    }

    public function test_worker_rejects_a_forged_redaction_marker_before_commit_creation(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real ticket-mutation worker proof requires the POSIX process and effect-lock runtime.');
        }

        $fixture = $this->managedFixture();
        $sourceContent = $this->validTicketMarkdown('AI6-910', 'todo');
        self::assertNotFalse(file_put_contents($fixture['source'].'/tickets/AI6-910.md', $sourceContent));
        $this->managedFixtureGit(['add', 'tickets/AI6-910.md'], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add marker fixture'], $fixture['source']);
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
            'tickets/AI6-910.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);
        $readModel = TicketReadModel::query()->where('relative_path', 'tickets/AI6-910.md')->firstOrFail();
        $operation = $this->app->make(QueueTicketMutation::class)->edit(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $sourceContent,
            str_replace('Ziel des Tickets.', 'Unmaskierter Zielstand.', $sourceContent),
            'Markerprüfung',
            true,
        );
        DB::table('jobs')->delete();
        $row = (array) DB::table('ticket_mutations')->where('status_operation_id', $operation->id)->first();
        DB::table('ticket_mutations')->where('status_operation_id', $operation->id)->delete();
        $parts = explode('Ziel des Tickets.', $sourceContent, 2);
        $row['target_content'] = $parts[0].RedactionMatchType::SECRET->marker().$parts[1];
        DB::table('ticket_mutations')->insert($row);
        $token = $fixture['lease']->claim($operation, str_repeat('3', 32));
        self::assertIsInt($token);

        try {
            $this->app->make(TicketMutationExecutor::class)->advance($operation, $token);
            self::fail('The worker accepted a forged redaction marker as write content.');
        } catch (ControlOperationTerminalConflict $exception) {
            self::assertSame('target_requires_redaction', $exception->conflict);
        }
        self::assertNull(TicketMutation::query()->findOrFail($operation->id)->prepared_commit_oid);
    }

    /**
     * @return array{
     *     administrator: User,
     *     project: Project,
     *     root: string,
     *     remote: string,
     *     parent_oid: string,
     *     runner: HardenedGitRunner,
     *     lease: ProjectOperationLease,
     *     read_model: TicketReadModel,
     *     operation: ControlOperation,
     *     attempt_token: int,
     *     executor: TicketMutationExecutor,
     *     repository: string,
     *     context: RedactionContext
     * }
     */
    private function recoveryMutationFixture(string $ticketId): array
    {
        $fixture = $this->managedFixture();
        $sourceContent = $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Recovery-Ausgangsstand.');
        $relativePath = 'tickets/'.$ticketId.'.md';
        self::assertNotFalse(file_put_contents($fixture['source'].'/'.$relativePath, $sourceContent));
        $this->managedFixtureGit(['add', $relativePath], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'add recovery fixture'], $fixture['source']);
        $parentOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
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
        $readModel = TicketReadModel::query()->where('relative_path', $relativePath)->firstOrFail();
        $operation = $this->app->make(QueueTicketMutation::class)->changeStatus(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $sourceContent,
            'Recovery-Nachweis',
            true,
            TicketStatusOperation::BLOCK,
            false,
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('6', 32));
        self::assertIsInt($attemptToken);
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->project_identifier),
        );
        $context = new RedactionContext(
            (string) $fixture['project']->getKey(),
            $operation->id,
            'ticket-mutation-recovery-test',
        );

        return [
            'administrator' => $fixture['administrator'],
            'project' => $fixture['project']->refresh(),
            'root' => $fixture['root'],
            'remote' => $fixture['remote'],
            'parent_oid' => $parentOid,
            'runner' => $fixture['runner'],
            'lease' => $fixture['lease'],
            'read_model' => $readModel,
            'operation' => $operation,
            'attempt_token' => $attemptToken,
            'executor' => $this->app->make(TicketMutationExecutor::class),
            'repository' => $repository,
            'context' => $context,
        ];
    }
}
