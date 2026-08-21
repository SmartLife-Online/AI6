<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Build an approved ticket and the finalized run that AI6-013 produces for it.
 *
 * The fixture drives the real approval and run-start seam instead of writing run rows directly, so
 * every consumer works against the same single mutation boundary.
 */
trait BuildsFinalizedRunFixture
{
    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    protected function queueStart(array $fixture): ControlOperation
    {
        return $this->app->make(QueueRunStart::class)->handle(
            $fixture['operator'],
            $fixture['project']->refresh(),
            $fixture['approval']->id,
            (string) Str::uuid(),
        );
    }

    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    protected function finalizedRun(array $fixture, ?string $confirmedCommitSha = null): Run
    {
        $operation = $this->queueStart($fixture);
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $target = $confirmedCommitSha ?? str_repeat('c', 64);
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

    /** @return array{operator: User, project: Project, approval: TicketApproval, attention: ?User} */
    protected function completedApproval(string $ticketId, ?Project $project = null, ?User $operator = null, ?User $attentionUser = null): array
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
        if ($attentionUser instanceof User) {
            $this->addMembership($attentionUser, $project, ProjectRole::APPROVER);
        }
        $todo = $this->validTicketMarkdown($ticketId, 'todo');
        $todoBlob = hash('sha256', 'blob '.strlen($todo)."\0".$todo);
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/'.$ticketId.'.md', $todo, ['blob_sha' => $todoBlob]);
        $selection = $this->approvalSelection($attentionUser);
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
            'attention' => $attentionUser,
        ];
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
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
            $attentionUser?->getKey(),
            'manual',
        );
    }
}
