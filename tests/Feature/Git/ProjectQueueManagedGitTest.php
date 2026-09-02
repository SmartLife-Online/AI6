<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalClaimStarter;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalQueue;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\QueueEligibility;
use App\AI6\Runs\QueueReevaluation;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AI6-030 TC-06 and TC-07: the queue contract across real control-history changes.
 */
final class ProjectQueueManagedGitTest extends TicketUiTestCase
{
    use BuildsManagedControlRuntimeFixture;

    public function test_control_branch_change_blocks_the_queued_approval_until_fetch_and_fresh_approval(): void
    {
        $this->requirePosixManagedGitRuntime('TC-06');

        $fixture = $this->managedFixture();
        $ticketId = 'AI6-QUEUE-BRANCH';
        $path = 'tickets/'.$ticketId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo');
        $this->commitFiles($fixture, [$path => $todo], 'add branch queue fixture');
        $this->managedFixtureGit(['push', $fixture['remote'], 'HEAD:refs/heads/next'], $fixture['source']);
        $this->cloneProject($fixture);
        $this->refreshTickets($fixture, [$path]);

        [$approver, $operator] = $this->queueActors($fixture['project']);
        $approval = $this->approveAndEnqueue($fixture, $approver, $path, $todo);
        self::assertTrue($this->app->make(QueueEligibility::class)->decide(
            $approval,
            $fixture['project']->refresh(),
        )['eligible']);
        $generation = $fixture['project']->refresh()->control_generation;

        $change = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->stepUpRequest($fixture['administrator']),
            $fixture['administrator'],
            $fixture['project']->refresh(),
            'refs/heads/next',
            (string) Str::uuid(),
        );
        $this->execute($change);

        $project = $fixture['project']->refresh();
        self::assertSame($generation + 1, $project->control_generation);
        self::assertSame('refs/heads/next', $project->pending_control_ref);
        $blocked = $this->app->make(QueueEligibility::class)->decide($approval->refresh(), $project);
        self::assertFalse($blocked['eligible']);
        self::assertContains('control_generation_changed', $blocked['reasons']);

        $evaluation = $this->app->make(QueueReevaluation::class)->scheduleApproval($approval->refresh());
        $this->app->call([new EvaluateTicketApproval($evaluation->id), 'handle']);
        $this->actingAs($operator)
            ->get(route('projects.queue.index', $project))
            ->assertOk()
            ->assertSee($ticketId)
            ->assertSee('control_generation_changed');
        try {
            $this->app->make(ApprovalClaimStarter::class)->start(
                $operator,
                $project,
                $approval->id,
                (string) Str::uuid(),
                automatic: true,
            );
            self::fail('The stale approval started automatically after the control-branch change.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('control_generation_changed', $exception->getMessage());
        }
        self::assertSame(0, ControlOperation::query()
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->count());
        self::assertSame('queued', $approval->refresh()->queue_state);

        $this->fetchProject($fixture);
        $this->refreshTickets($fixture, [$path]);
        $project = $fixture['project']->refresh();
        self::assertSame('refs/heads/next', $project->control_branch);
        self::assertNull($project->pending_control_ref);
        self::assertNotNull($project->control_oid);

        $freshApproval = $this->approveAndEnqueue($fixture, $approver, $path, $todo);
        self::assertTrue($this->app->make(QueueEligibility::class)->decide(
            $freshApproval,
            $fixture['project']->refresh(),
        )['eligible']);
    }

    public function test_foreign_fast_forward_sequence_ends_in_one_atomic_claim_from_the_fresh_parent(): void
    {
        $this->requirePosixManagedGitRuntime('TC-07');

        $fixture = $this->managedFixture();
        $ticketId = 'AI6-QUEUE-SEQUENCE';
        $dependencyId = 'AI6-QUEUE-DEPENDENCY';
        $foreignId = 'AI6-QUEUE-FOREIGN';
        $ticketPath = 'tickets/'.$ticketId.'.md';
        $dependencyPath = 'tickets/'.$dependencyId.'.md';
        $foreignPath = 'tickets/'.$foreignId.'.md';
        $todo = $this->validTicketMarkdown($ticketId, 'todo', '["'.$dependencyId.'"]');
        $dependencyTodo = $this->validTicketMarkdown($dependencyId, 'todo');
        $foreignReady = $this->validTicketMarkdown($foreignId, 'ready');
        $this->commitFiles($fixture, [
            $ticketPath => $todo,
            $dependencyPath => $dependencyTodo,
            $foreignPath => $foreignReady,
        ], 'add lineage queue fixtures');
        $this->cloneProject($fixture);
        $this->refreshTickets($fixture, [$ticketPath, $dependencyPath, $foreignPath]);

        [$approver, $operator] = $this->queueActors($fixture['project']);
        $approval = $this->approveAndEnqueue($fixture, $approver, $ticketPath, $todo);
        $initial = $this->app->make(QueueEligibility::class)->decide(
            $approval,
            $fixture['project']->refresh(),
        );
        self::assertFalse($initial['eligible']);
        self::assertContains('dependency_unsatisfied:'.$dependencyId, $initial['reasons']);
        $this->synchronizeSource($fixture);

        $this->commitFiles($fixture, [
            $foreignPath => str_replace('status: ready', 'status: in_progress', $foreignReady),
        ], 'foreign run claim');
        $this->fetchAndRefresh($fixture, [$ticketPath, $dependencyPath, $foreignPath]);
        $this->assertQueuedFastForward($approval, $fixture['project'], $dependencyId);

        $foreignInProgress = (string) file_get_contents($fixture['source'].'/'.$foreignPath);
        $this->commitFiles($fixture, [
            $foreignPath => str_replace('status: in_progress', 'status: done', $foreignInProgress),
        ], 'foreign status synchronization');
        $this->fetchAndRefresh($fixture, [$ticketPath, $dependencyPath, $foreignPath]);
        $this->assertQueuedFastForward($approval, $fixture['project'], $dependencyId);

        $this->commitFiles($fixture, [
            $dependencyPath => str_replace('status: todo', 'status: done', $dependencyTodo),
        ], 'complete queue dependency');
        $this->fetchAndRefresh($fixture, [$ticketPath, $dependencyPath, $foreignPath]);
        $claimParent = $fixture['project']->refresh()->control_oid;
        self::assertIsString($claimParent);
        self::assertTrue($this->app->make(QueueEligibility::class)->decide(
            $approval->refresh(),
            $fixture['project']->refresh(),
        )['eligible']);

        $runStart = $this->app->make(ApprovalClaimStarter::class)->start(
            $operator,
            $fixture['project']->refresh(),
            $approval->id,
            (string) Str::uuid(),
        );
        $this->execute($runStart);

        $run = Run::query()->sole();
        self::assertSame($approval->id, $run->ticket_approval_id);
        self::assertSame($claimParent, $run->claim_parent_control_sha);
        self::assertSame('consumed', $approval->refresh()->queue_state);
        self::assertSame(ControlOperationState::COMPLETED, $runStart->refresh()->state);
        self::assertSame(1, ControlOperation::query()
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->count());
        self::assertSame($run->id, $fixture['project']->refresh()->active_run_id);
        self::assertNull($fixture['project']->refresh()->operation_lock_operation_id);
    }

    private function requirePosixManagedGitRuntime(string $case): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped($case.' requires the POSIX process and effect-lock runtime.');
        }
    }

    private function stepUpRequest(User $actor): Request
    {
        $session = new Store('queue-managed-git-test', new ArraySessionHandler(120));
        $session->setId('queue-managed-git-'.bin2hex(random_bytes(8)));
        $session->start();
        $request = Request::create('/projects/control-branch', 'POST');
        $request->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $request,
            $actor,
            QueueControlBranchChange::STEP_UP_ACTION,
        );

        return $request;
    }

    /** @return array{User, User} */
    private function queueActors(Project $project): array
    {
        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);

        return [$approver, $operator];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  array<string, string>  $files
     */
    private function commitFiles(array $fixture, array $files, string $message): void
    {
        foreach ($files as $path => $content) {
            self::assertNotFalse(file_put_contents($fixture['source'].'/'.$path, $content));
        }
        $this->managedFixtureGit(['add', ...array_keys($files)], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', $message], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'HEAD:refs/heads/main'], $fixture['source']);
    }

    /** @param array<string, mixed> $fixture */
    private function cloneProject(array $fixture): void
    {
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        $this->execute($operation);
    }

    /** @param array<string, mixed> $fixture */
    private function fetchProject(array $fixture): void
    {
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        $this->execute($operation);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $paths
     */
    private function refreshTickets(array $fixture, array $paths): void
    {
        foreach ($paths as $path) {
            $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
                $fixture['administrator'],
                $fixture['project']->refresh(),
                $path,
                (string) Str::uuid(),
            );
            $this->execute($operation);
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $paths
     */
    private function fetchAndRefresh(array $fixture, array $paths): void
    {
        $this->fetchProject($fixture);
        $this->refreshTickets($fixture, $paths);
    }

    /** @param array<string, mixed> $fixture */
    private function synchronizeSource(array $fixture): void
    {
        $this->managedFixtureGit(['fetch', $fixture['remote'], 'refs/heads/main'], $fixture['source']);
        $this->managedFixtureGit(['reset', '--hard', 'FETCH_HEAD'], $fixture['source']);
    }

    /** @param array<string, mixed> $fixture */
    private function approveAndEnqueue(array $fixture, User $approver, string $path, string $source): TicketApproval
    {
        $readModel = TicketReadModel::query()
            ->where('project_id', $fixture['project']->getKey())
            ->where('relative_path', $path)
            ->where('control_commit', $fixture['project']->refresh()->control_oid)
            ->latest('generated_at')
            ->firstOrFail();
        $selection = $this->approvalSelection();
        $approvalId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $fixture['project']->refresh(),
            $readModel,
            $selection,
            $approvalId,
        );
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $fixture['project']->refresh(),
            $readModel,
            $approvalId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $source,
            'Managed-Git-Queuefreigabe',
            true,
            $selection,
            $snapshot,
            $approvalId,
        );
        $this->execute($operation);
        $approval = TicketApproval::query()->findOrFail($approvalId);
        self::assertSame('complete', $approval->saga_phase);
        self::assertSame('available', $approval->queue_state);

        return $this->app->make(ApprovalQueue::class)->enqueue(
            $fixture['project']->refresh(),
            $approval->id,
            $approval->version,
        );
    }

    private function assertQueuedFastForward(TicketApproval $approval, Project $project, string $dependencyId): void
    {
        $decision = $this->app->make(QueueEligibility::class)->decide($approval->refresh(), $project->refresh());
        self::assertFalse($decision['eligible']);
        self::assertContains('dependency_unsatisfied:'.$dependencyId, $decision['reasons']);
        self::assertNotContains('control_generation_changed', $decision['reasons']);
        self::assertNotContains('ticket_blob_changed', $decision['reasons']);
        self::assertSame('queued', $approval->refresh()->queue_state);
        self::assertSame(0, ControlOperation::query()
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->count());
    }

    private function execute(ControlOperation $operation): void
    {
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
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
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            null,
            'manual',
        );
    }
}
