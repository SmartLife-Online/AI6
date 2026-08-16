<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RunStartContractTest extends TicketUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_browser_queue_creates_only_one_typed_run_start_intent(): void
    {
        $fixture = $this->completedApproval('AI6-START-1');
        $operation = $this->queueStart($fixture);

        self::assertSame(ControlOperationType::RUN_START, $operation->operation_type);
        self::assertSame(ControlOperationState::QUEUED, $operation->state);
        self::assertSame(ControlOperationPhase::PREPARED, $operation->phase);
        self::assertSame(1, TicketMutation::query()->whereKey($operation->id)->count());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, Run::query()->count());
        self::assertNull($fixture['project']->fresh()->active_run_id);

        try {
            $this->queueStart($fixture);
            self::fail('A concurrent start acquired the same project claim.');
        } catch (ControlOperationConflict) {
            self::assertSame(1, ControlOperation::query()->where('operation_type', ControlOperationType::RUN_START)->count());
            self::assertSame(0, Run::query()->count());
        }
    }

    public function test_initial_claim_rejects_each_shared_project_conflict_in_the_atomic_seam(): void
    {
        $fixture = $this->completedApproval('AI6-START-2');
        $project = $fixture['project'];
        $project->forceFill([
            'pending_control_ref' => 'refs/heads/main',
            'pending_control_oid' => $project->control_oid,
            'pending_control_operation_id' => $fixture['approval']->status_operation_id,
        ])->save();

        $this->expectException(ControlOperationConflict::class);
        $this->queueStart($fixture);
    }

    public function test_confirmed_owned_claim_finalizes_exactly_one_run_and_releases_only_the_operation_lease(): void
    {
        $fixture = $this->completedApproval('AI6-START-3');
        $operation = $this->queueStart($fixture);
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $target = str_repeat('c', 64);
        self::assertSame(1, Project::query()->whereKey($fixture['project']->getKey())->update([
            'control_oid' => $target,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]));
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $target,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]));

        $operation->refresh();
        $run = $this->app->make(RunOrchestrator::class)->finalizeClaim(
            $fixture['approval']->refresh(),
            $operation,
            $attemptToken,
            (string) $operation->expected_control_commit,
            $target,
        );
        self::assertSame($operation->id, $run->status_operation_id);
        self::assertSame($target, $run->initial_run_base_sha);
        self::assertSame($target, $run->run_base_sha);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        self::assertNull($fixture['project']->fresh()->operation_lock_operation_id);
        self::assertSame('consumed', $fixture['approval']->refresh()->queue_state);
        self::assertSame($run->id, $this->app->make(RunOrchestrator::class)->finalizeClaim(
            $fixture['approval'],
            $operation,
            $attemptToken,
            (string) $operation->expected_control_commit,
            $target,
        )->id);
        self::assertSame(1, Run::query()->count());

        Project::query()->whereKey($fixture['project']->getKey())->update([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_lease_expires_at' => now()->addMinute(),
            'operation_lock_heartbeat_at' => now(),
        ]);
        try {
            $this->app->make(RunOrchestrator::class)->finalizeClaim(
                $fixture['approval'],
                $operation,
                $attemptToken,
                (string) $operation->expected_control_commit,
                $target,
            );
            self::fail('A replay accepted a run-start lease that was not released.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('claim_lease_not_released', $conflict->reason);
        }
    }

    public function test_run_start_and_control_branch_change_share_one_claim_and_publish_guard(): void
    {
        $branchFirst = $this->completedApproval('AI6-START-RACE-1');
        $branchFirst['project']->forceFill(['control_branch' => 'refs/heads/release'])->save();
        $branchActor = $this->createUser(['is_global_admin' => true]);
        $this->addMembership($branchActor, $branchFirst['project'], ProjectRole::ADMIN);
        $branch = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->stepUpRequest($branchActor),
            $branchActor,
            $branchFirst['project']->refresh(),
            'refs/heads/main',
            (string) Str::uuid(),
        );
        try {
            $this->queueStart($branchFirst);
            self::fail('A run start acquired the control-branch operation lease.');
        } catch (ControlOperationConflict) {
            self::assertSame($branch->id, $branchFirst['project']->fresh()->operation_lock_operation_id);
        }
        ControlOperationResult::query()->create([
            'control_operation_id' => $branch->id,
            'outcome' => 'failed',
            'result_binding' => hash('sha256', 'branch-race-release-'.$branch->id),
            'safe_summary' => 'Der Branchwechsel wurde für die Gegenrichtung beendet.',
        ]);
        ControlOperation::query()->whereKey($branch->id)->update([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
            'state' => ControlOperationState::FAILED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release(
            $branch->id,
            $branch->project_id,
            (int) $branch->current_attempt_token,
        ));

        $runStart = $this->queueStart($branchFirst);
        $secondBranchActor = $this->createUser(['is_global_admin' => true]);
        $this->addMembership($secondBranchActor, $branchFirst['project'], ProjectRole::ADMIN);
        try {
            $this->app->make(QueueControlBranchChange::class)->handle(
                $this->stepUpRequest($secondBranchActor),
                $secondBranchActor,
                $branchFirst['project']->refresh(),
                'refs/heads/main',
                (string) Str::uuid(),
            );
            self::fail('A control-branch change acquired the run-start operation lease.');
        } catch (ControlOperationConflict) {
            self::assertSame($runStart->id, $branchFirst['project']->fresh()->operation_lock_operation_id);
        }
        self::assertSame(0, Run::query()->count());
        $publisher = file_get_contents(base_path('app/AI6/Git/ControlBranchChanger.php'));
        self::assertIsString($publisher);
        self::assertStringContainsString("->whereNull('active_run_id')", $publisher);
    }

    public function test_failed_and_unconfirmed_terminal_transitions_keep_the_project_lock(): void
    {
        $fixture = $this->completedApproval('AI6-START-4');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);

        $failed = $orchestrator->transition($run, $run->version, RunState::FAILED, RunPhase::PREPARE);
        self::assertSame($failed->id, $fixture['project']->fresh()->active_run_id);
        try {
            $orchestrator->transition($failed, $failed->version, RunState::CANCELLED, RunPhase::FINALIZE);
            self::fail('An unconfirmed terminal status released the project lock.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('terminal_status_not_confirmed', $conflict->reason);
            self::assertSame($failed->id, $fixture['project']->fresh()->active_run_id);
        }
    }

    public function test_terminal_status_must_belong_to_the_run_ticket(): void
    {
        $fixture = $this->completedApproval('AI6-START-BOUND-1');
        $run = $this->finalizedRun($fixture);
        $foreign = $this->completedTerminalStatusOperation($fixture, $run, 'tickets/AI6-FOREIGN.md');

        try {
            $this->app->make(RunOrchestrator::class)->transition(
                $run,
                $run->version,
                RunState::CANCELLED,
                RunPhase::FINALIZE,
                confirmedStatusOperation: $foreign,
            );
            self::fail('A terminal status operation for another ticket released the run lock.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('terminal_status_not_run_bound', $conflict->reason);
            self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
            self::assertSame($run->run_base_sha, $run->fresh()->run_base_sha);
        }
    }

    public function test_confirmed_run_bound_terminal_status_releases_lock_and_allows_a_follow_up_start(): void
    {
        $fixture = $this->completedApproval('AI6-START-RELEASE-1');
        $run = $this->finalizedRun($fixture);
        $terminal = $this->completedTerminalStatusOperation(
            $fixture,
            $run,
            $fixture['approval']->relative_path,
        );

        $cancelled = $this->app->make(RunOrchestrator::class)->transition(
            $run,
            $run->version,
            RunState::CANCELLED,
            RunPhase::FINALIZE,
            confirmedStatusOperation: $terminal,
        );
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertSame($terminal->target_control_oid, $cancelled->run_base_sha);
        self::assertNull($fixture['project']->fresh()->active_run_id);

        $followUp = $this->completedApproval(
            'AI6-START-RELEASE-2',
            $fixture['project']->refresh(),
            $fixture['operator'],
        );
        $nextStart = $this->queueStart($followUp);
        self::assertSame(ControlOperationType::RUN_START, $nextStart->operation_type);
        self::assertSame($nextStart->id, $fixture['project']->fresh()->operation_lock_operation_id);
    }

    public function test_terminal_run_state_requires_its_matching_ticket_status_edge(): void
    {
        $cancelFixture = $this->completedApproval('AI6-START-TARGET-CANCEL');
        $cancelRun = $this->finalizedRun($cancelFixture);
        $cancelRun = $this->app->make(RunOrchestrator::class)->transition(
            $cancelRun,
            $cancelRun->version,
            RunState::RUNNING,
            RunPhase::IMPLEMENT,
        );
        $softCancel = $this->completedTerminalStatusOperation(
            $cancelFixture,
            $cancelRun,
            $cancelFixture['approval']->relative_path,
        );

        try {
            $this->app->make(RunOrchestrator::class)->transition(
                $cancelRun,
                $cancelRun->version,
                RunState::COMPLETED,
                RunPhase::FINALIZE,
                confirmedStatusOperation: $softCancel,
            );
            self::fail('A soft-cancel status edge completed the run.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('terminal_status_target_conflict', $conflict->reason);
            self::assertSame($cancelRun->id, $cancelFixture['project']->fresh()->active_run_id);
            self::assertSame($cancelRun->run_base_sha, $cancelRun->fresh()->run_base_sha);
        }
    }

    public function test_stale_versions_are_effect_free_and_identical_delivery_is_idempotent(): void
    {
        $fixture = $this->completedApproval('AI6-START-5');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $running = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::IMPLEMENT);
        self::assertSame($running->version, $orchestrator->transition($running, $running->version, RunState::RUNNING, RunPhase::IMPLEMENT)->version);

        $this->expectException(RunTransitionConflict::class);
        $orchestrator->transition($running, $run->version, RunState::WAITING, RunPhase::IMPLEMENT);
    }

    public function test_active_run_blocks_run_and_branch_claims_but_not_the_shared_refresh_seam(): void
    {
        $fixture = $this->completedApproval('AI6-START-6');
        $this->finalizedRun($fixture);
        $lease = $this->app->make(ProjectOperationLease::class);

        self::assertNull($lease->claimInitialControlOperation(
            $fixture['project']->refresh(),
            (string) Str::uuid(),
            requiresInactiveRun: true,
        ));
        $refreshOperationId = (string) Str::uuid();
        self::assertIsInt($lease->claimInitialControlOperation(
            $fixture['project']->refresh(),
            $refreshOperationId,
        ));
        self::assertSame($refreshOperationId, $fixture['project']->fresh()->operation_lock_operation_id);
    }

    public function test_http_start_is_authorized_and_queues_without_creating_a_run(): void
    {
        $fixture = $this->completedApproval('AI6-START-7');
        $viewer = $this->createUser();
        $this->addMembership($viewer, $fixture['project'], ProjectRole::VIEWER);
        $this->actingAs($viewer)
            ->post(route('projects.approvals.start', [$fixture['project'], $fixture['approval']]))
            ->assertForbidden();

        $this->actingAs($fixture['operator'])
            ->post(route('projects.approvals.start', [$fixture['project'], $fixture['approval']]))
            ->assertRedirect();
        self::assertSame(1, ControlOperation::query()->where('operation_type', ControlOperationType::RUN_START)->count());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, Run::query()->count());
    }

    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    private function queueStart(array $fixture): ControlOperation
    {
        return $this->app->make(QueueRunStart::class)->handle(
            $fixture['operator'],
            $fixture['project']->refresh(),
            $fixture['approval']->id,
            (string) Str::uuid(),
        );
    }

    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    private function finalizedRun(array $fixture): Run
    {
        $operation = $this->queueStart($fixture);
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $target = str_repeat('c', 64);
        Project::query()->whereKey($fixture['project']->getKey())->update([
            'control_oid' => $target,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]);
        ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $target,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]);

        return $this->app->make(RunOrchestrator::class)->finalizeClaim(
            $fixture['approval']->refresh(),
            $operation->refresh(),
            $attemptToken,
            (string) $operation->expected_control_commit,
            $target,
        );
    }

    /** @return array{operator: User, project: Project, approval: TicketApproval} */
    private function completedApproval(string $ticketId, ?Project $project = null, ?User $operator = null): array
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $operator ??= $this->createUser();
        $newProject = $project === null;
        $project ??= $this->provisionedProject($administrator);
        if (! $newProject) {
            $this->addMembership($administrator, $project, ProjectRole::ADMIN);
        }
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        if ($newProject) {
            $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        }
        $todo = $this->validTicketMarkdown($ticketId, 'todo');
        $todoBlob = hash('sha256', 'blob '.strlen($todo)."\0".$todo);
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/'.$ticketId.'.md', $todo, ['blob_sha' => $todoBlob]);
        $selection = $this->selection();
        $operationId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create($project, $readModel, $selection, $operationId);
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $todo,
            'Runstart-Vertrag vorbereiten',
            true,
            $selection,
            $snapshot,
            $operationId,
        );
        DB::table('jobs')->delete();
        $ready = str_replace('status: todo', 'status: ready', $todo);
        $readyBlob = hash('sha256', 'blob '.strlen($ready)."\0".$ready);
        self::assertSame(1, TicketApproval::query()->whereKey($operationId)->update([
            'approved_ticket_blob_sha' => $readyBlob,
            'approved_control_sha' => $project->control_oid,
            'intended_commit_sha' => $project->control_oid,
            'saga_phase' => 'complete',
            'queue_state' => 'queued',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('e', 32));
        self::assertIsInt($attemptToken);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $project->control_oid,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]));
        self::assertSame(1, DB::table('ticket_read_models')->where('id', $readModel->id)->update([
            'control_operation_id' => $operation->id,
            'blob_sha' => $readyBlob,
            'redacted_content' => $ready,
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => Date::now(),
            'updated_at' => Date::now(),
        ]));
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Approval-Operation.',
        ]);
        ControlOperation::query()->whereKey($operation->id)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => Date::now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]);
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release($operation->id, $operation->project_id, $attemptToken));
        DB::table('jobs')->delete();

        return [
            'operator' => $operator,
            'project' => $project->refresh(),
            'approval' => TicketApproval::query()->findOrFail($operationId),
        ];
    }

    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    private function completedTerminalStatusOperation(array $fixture, Run $run, string $relativePath): ControlOperation
    {
        $operationId = (string) Str::uuid();
        $targetOid = hash('sha256', 'terminal-status-'.$operationId);
        $parameters = json_encode([
            'expected_binding_version' => $fixture['project']->refresh()->control_binding_version,
            'relative_path' => $relativePath,
            'status_operation' => 'return_to_todo',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $seed = ControlOperation::query()->findOrFail($run->status_operation_id);
        $operation = ControlOperation::query()->create([
            'id' => $operationId,
            'project_id' => $run->project_id,
            'actor_id' => $fixture['operator']->getKey(),
            'operation_type' => ControlOperationType::TICKET_STATUS_CHANGE,
            'schema_version' => 1,
            'authorization_snapshot' => $seed->authorization_snapshot,
            'authorization_snapshot_jcs' => $seed->authorization_snapshot_jcs,
            'expected_control_commit' => $run->run_base_sha,
            'operation_parameters_jcs' => $parameters,
            'request_hash' => hash('sha256', 'terminal-request-'.$operationId),
            'phase' => ControlOperationPhase::PREPARED,
            'state' => ControlOperationState::QUEUED,
        ]);
        $base = $this->validTicketMarkdown('AI6-TERMINAL', 'in_progress');
        $target = str_replace('status: in_progress', 'status: todo', $base);
        TicketMutation::query()->create([
            'status_operation_id' => $operationId,
            'relative_path' => $relativePath,
            'expected_ticket_blob_sha' => hash('sha256', 'terminal-source-blob-'.$operationId),
            'base_content_sha256' => hash('sha256', $base),
            'base_content' => $base,
            'target_content' => $target,
            'source_status' => 'in_progress',
            'target_status' => 'todo',
            'source_contract_sha256' => hash('sha256', 'terminal-source-contract-'.$operationId),
            'target_contract_sha256' => hash('sha256', 'terminal-target-contract-'.$operationId),
            'expected_target_blob_sha' => hash('sha256', 'terminal-target-blob-'.$operationId),
            'expected_target_tree_oid' => hash('sha256', 'terminal-target-tree-'.$operationId),
            'expected_control_binding_version' => $fixture['project']->control_binding_version,
            'audit_reason' => 'Gebundener kontrollierter Runabbruch.',
            'audit_redaction_matches' => [],
            'external_completion_confirmed' => false,
            'commit_timestamp' => now()->getTimestamp(),
        ]);
        ControlOperation::query()->whereKey($operationId)->update([
            'state' => ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'started_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        TicketMutation::query()->whereKey($operationId)->update([
            'prepared_commit_oid' => $targetOid,
            'prepared_attempt_token' => 1,
        ]);
        ControlOperation::query()->whereKey($operationId)->update([
            'target_control_oid' => $targetOid,
            'phase' => ControlOperationPhase::COMMIT_PREPARED,
            'version' => DB::raw('version + 1'),
        ]);
        ControlOperationResult::query()->create([
            'control_operation_id' => $operationId,
            'outcome' => 'succeeded',
            'result_binding' => hash('sha256', 'terminal-result-'.$operationId),
            'safe_summary' => 'Die gebundene terminale Statusoperation wurde bestätigt.',
        ]);
        ControlOperation::query()->whereKey($operationId)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        Project::query()->whereKey($run->project_id)->update([
            'control_oid' => $targetOid,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]);

        return $operation->refresh();
    }

    private function stepUpRequest(User $actor): Request
    {
        $session = new Store('run-start-branch-race', new ArraySessionHandler(120));
        $session->setId('run-start-branch-'.bin2hex(random_bytes(8)));
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

    private function selection(): ApprovalSelection
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
