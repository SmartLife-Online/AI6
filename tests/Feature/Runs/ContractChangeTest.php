<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
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

        $resolved = $service->resolveReturnToTodo($run->fresh(), $operation->refresh());

        self::assertSame(RunState::CANCELLED, $resolved->state);
        // The change becomes effective only through a new approval, run and
        // sessions: the old run ends without any session to take over.
        self::assertNull(RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->value('session_id'));
        self::assertNull($fixture['project']->fresh()->active_run_id);
    }

    public function test_the_cancel_path_ends_the_wait_through_the_abort_path(): void
    {
        $fixture = $this->runningRun('AI6-020-CC-CANCEL');
        $run = $this->app->make(ContractChangeService::class)->request($fixture['run'], $fixture['project']);

        $cancelled = $this->app->make(ContractChangeService::class)->cancel($run);

        self::assertSame(RunState::FAILED, $cancelled->state);
    }
}
