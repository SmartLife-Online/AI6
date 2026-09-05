<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ApprovalClaimStarter;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ContractChangeService;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketStatusOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-10: the separate instruction/runtime profile change path (AC-11).
 *
 * A requested change parks the run behind contract_change, discards the
 * provider sessions, keeps the old run's run_base_sha untouched and resolves
 * exclusively through the controlled in_progress→todo status saga into a
 * cancelled run — new approval, new run, new sessions. External drift ends as
 * git_base_changed instead.
 */
final class ContractChangeTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** @return array{run: Run, project: Project, admin: User, ticketId: string} */
    private function runningRun(string $ticketId): array
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $admin = $this->createUser(['is_global_admin' => true]);
        $fixture = $this->completedApproval($ticketId, operator: $admin);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));

        return [
            'run' => $run,
            'project' => $fixture['project']->refresh(),
            'admin' => $admin,
            'ticketId' => $ticketId,
        ];
    }

    public function test_a_requested_change_parks_the_run_and_discards_sessions_without_moving_the_base(): void
    {
        $fixture = $this->runningRun('AI6-020-CC-PARK');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $slot = $orchestrator->ensureImplementationSlot($run);
        $orchestrator->bindImplementationSession($run, $slot->slot_id, 'session-1');
        $baseBefore = $run->run_base_sha;

        $parked = $this->app->make(ContractChangeService::class)->request($run, $fixture['project']);

        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::CONTRACT_CHANGE, $parked->wait_reason);
        self::assertSame($baseBefore, $parked->run_base_sha);
        self::assertNull(RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->firstOrFail()->session_id);
    }

    public function test_external_drift_ends_as_git_base_changed_instead(): void
    {
        $fixture = $this->runningRun('AI6-020-CC-DRIFT');
        $run = $fixture['run'];
        self::assertSame(1, Project::query()->whereKey($fixture['project']->getKey())->update([
            'control_oid' => str_repeat('9', 64),
        ]));

        $parked = $this->app->make(ContractChangeService::class)->request($run, $fixture['project']->refresh());

        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $parked->wait_reason);
    }

    public function test_the_controlled_reset_resolves_into_a_cancelled_run_via_the_status_saga(): void
    {
        $fixture = $this->runningRun('AI6-020-CC-RESET');
        $run = $fixture['run'];
        $service = $this->app->make(ContractChangeService::class);
        $run = $service->request($run, $fixture['project']);

        $resolved = $this->controlledReset($fixture, $run, $service);

        self::assertSame(RunState::CANCELLED, $resolved->state);
        // The change becomes effective only through a new approval, run and
        // sessions: the old run ends without any session to take over.
        self::assertNull(RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->value('session_id'));
        self::assertNull($fixture['project']->fresh()->active_run_id);
    }

    /**
     * Drive the one controlled reset saga to its terminal state.
     *
     * @param  array{run: Run, project: Project, admin: User, ticketId: string}  $fixture
     */
    private function controlledReset(array $fixture, Run $run, ContractChangeService $service): Run
    {
        // The controlled reset is a real status mutation through the one
        // existing saga: in_progress → todo via return_to_todo.
        $base = $this->validTicketMarkdown($fixture['ticketId'], 'in_progress');
        $baseBlob = hash('sha256', 'blob '.strlen($base)."\0".$base);
        $readModel = TicketReadModel::query()
            ->where('project_id', $run->project_id)
            ->where('relative_path', 'tickets/'.$fixture['ticketId'].'.md')
            ->firstOrFail();
        self::assertSame(1, DB::table('ticket_read_models')->where('id', $readModel->id)->update([
            'control_operation_id' => $run->status_operation_id,
            'control_commit' => $run->run_base_sha,
            'blob_sha' => $baseBlob,
            'redacted_content' => $base,
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));

        $operation = $this->app->make(QueueTicketMutation::class)->changeStatus(
            $fixture['admin'],
            $fixture['project']->refresh(),
            $readModel->refresh(),
            (string) Str::uuid(),
            $run->run_base_sha,
            $baseBlob,
            $base,
            'Instruktionsänderung verlangt neuen Vertrag',
            true,
            TicketStatusOperation::RETURN_TO_TODO,
            false,
        );
        DB::table('jobs')->delete();
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertSame('in_progress', $mutation->source_status);
        self::assertSame('todo', $mutation->target_status);

        // Simulate the confirmed worker phases of the status saga.
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $terminalCommit = str_repeat('d', 64);
        self::assertSame(1, TicketMutation::query()->whereKey($operation->id)->update([
            'prepared_commit_oid' => $terminalCommit,
            'prepared_attempt_token' => $attemptToken,
            'updated_at' => now(),
        ]));
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $terminalCommit,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Statusoperation.',
        ]);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release($operation->id, $operation->project_id, $attemptToken));
        self::assertSame(1, Project::query()->whereKey($fixture['project']->getKey())->update([
            'control_oid' => $terminalCommit,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]));

        return $service->resolveReturnToTodo($run->fresh(), $operation->refresh());
    }

    /**
     * TC-11/TC-12: the whole lineage after a refused same-run instruction
     * change. The reset ends the old run; only a new approval binds the
     * changed instruction bytes, that approval produces a new run with a new
     * session, and the consumed old approval can no longer be started.
     */
    public function test_the_reset_lineage_binds_the_changed_instruction_to_a_new_approval_run_and_session(): void
    {
        $ticketId = 'AI6-032-CC-LINEAGE';
        $fixture = $this->runningRun($ticketId);
        $run = $fixture['run'];
        $project = $fixture['project'];
        $oldApprovalId = $run->ticket_approval_id;
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $slot = $orchestrator->ensureImplementationSlot($run);
        $orchestrator->bindImplementationSession($run, $slot->slot_id, 'session-old');
        $provider = ($run->agent_profile_snapshot ?? [])['implementation']['provider_profile'] ?? null;
        self::assertIsString($provider);
        $oldInstructionHash = ($run->instruction_snapshot ?? [])[$provider]['instruction_snapshot_hash'] ?? null;
        self::assertIsString($oldInstructionHash);

        $service = $this->app->make(ContractChangeService::class);
        $parked = $service->request($run, $project);
        self::assertSame(WaitReason::CONTRACT_CHANGE, $parked->wait_reason);
        self::assertNull(RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->value('session_id'));

        $cancelled = $this->controlledReset($fixture, $parked, $service);
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($project->fresh()->active_run_id);
        // The parked run keeps its own binding: the reset never rewrites it.
        self::assertSame($oldInstructionHash, ($cancelled->instruction_snapshot ?? [])[$provider]['instruction_snapshot_hash'] ?? null);

        // Only from here on does the released instruction file reach a binding.
        $updated = "# neue freigegebene Instruktion\n";
        $this->app->instance(InstructionCandidateSource::class, new class($updated) implements InstructionCandidateSource
        {
            public function __construct(private readonly string $content) {}

            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [new InstructionCandidate(
                    'agents_md',
                    InstructionCandidateOrigin::REPOSITORY,
                    true,
                    InstructionFileType::REGULAR,
                    'AGENTS.md',
                    str_repeat('b', 40),
                    $this->content,
                )];
            }
        });
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);

        // The control branch carries the ticket back at `todo`; the read-model
        // refresh replaces the stale projection. This projection row is the one
        // simulated Git effect — approval, run and sessions below are produced
        // exclusively by their real services.
        TicketReadModel::query()->where('project_id', $project->getKey())
            ->where('relative_path', 'tickets/'.$ticketId.'.md')->delete();
        $second = $this->completedApproval($ticketId, project: $project->refresh(), operator: $fixture['admin']);
        self::assertNotSame($oldApprovalId, $second['approval']->id);
        $newRun = $this->finishPreflight($this->bindRunWorkspace($second, $this->finalizedRun($second)));

        self::assertNotSame($run->id, $newRun->id);
        self::assertSame($second['approval']->id, $newRun->ticket_approval_id);
        self::assertSame(RunState::RUNNING, $newRun->state);
        $newProvider = ($newRun->agent_profile_snapshot ?? [])['implementation']['provider_profile'] ?? null;
        self::assertIsString($newProvider);
        $newBinding = ($newRun->instruction_snapshot ?? [])[$newProvider] ?? null;
        self::assertIsArray($newBinding);
        self::assertNotSame($oldInstructionHash, $newBinding['instruction_snapshot_hash'] ?? null);
        self::assertSame($updated, $newBinding['entries'][0]['effective_content'] ?? null);

        $newSlot = $orchestrator->ensureImplementationSlot($newRun);
        $orchestrator->bindImplementationSession($newRun, $newSlot->slot_id, 'session-new');
        self::assertSame('session-new', $newSlot->fresh()->session_id);
        self::assertNotSame($slot->slot_id, $newSlot->slot_id);
        self::assertNull(RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->value('session_id'));

        // The consumed approval of the cancelled lineage cannot be started again.
        try {
            $this->app->make(ApprovalClaimStarter::class)->start(
                $fixture['admin'],
                $project->refresh(),
                (string) $oldApprovalId,
                (string) Str::uuid(),
            );
            self::fail('The consumed approval of the reset lineage was startable again.');
        } catch (ControlOperationConflict $conflict) {
            self::assertStringContainsString('not eligible', $conflict->getMessage());
        }
    }

    public function test_the_cancel_path_ends_the_wait_through_the_abort_path(): void
    {
        $fixture = $this->runningRun('AI6-020-CC-CANCEL');
        $run = $this->app->make(ContractChangeService::class)->request($fixture['run'], $fixture['project']);

        $cancelled = $this->app->make(ContractChangeService::class)->cancel($run);

        self::assertSame(RunState::FAILED, $cancelled->state);
    }
}
