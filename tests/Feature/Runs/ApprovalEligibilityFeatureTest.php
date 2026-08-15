<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalFreshness;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ApprovalEligibilityFeatureTest extends TicketUiTestCase
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

    public function test_status_only_projection_stays_valid_while_blob_and_config_changes_invalidate(): void
    {
        $fixture = $this->completedApproval('E1');
        self::assertSame($fixture['approval']->ticket_contract_sha256, $fixture['read_model']->ticket_contract_sha256);
        self::assertSame([], $this->evaluate($fixture['approval']));
        $readyProjection = $this->app->make(TicketReadModelProjector::class)->project(
            $fixture['ready_content'],
            'tickets/E1.md',
            TicketValidationProfile::GENERIC_V1,
        );
        self::assertSame($fixture['approval']->ticket_contract_sha256, $readyProjection->contractHash);
        self::assertSame($fixture['approval']->reviewed_ticket_blob_sha, $fixture['approval']->approved_ticket_blob_sha);

        config(['ai6.project_config.server_defaults.push_mode' => 'automatic_after_gates']);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        self::assertContains('config_changed', $this->evaluate($fixture['approval']));
        config(['ai6.project_config.server_defaults.push_mode' => 'manual']);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);

        $changed = $fixture['read_model']->replicate()->forceFill([
            'blob_sha' => str_repeat('f', 64),
            'ticket_contract_sha256' => str_repeat('e', 64),
        ]);
        $binding = $this->app->make(EffectiveProjectConfiguration::class)->for($fixture['project']);
        $reasons = $this->app->make(ApprovalFreshness::class)->reasons(
            $fixture['approval'],
            $fixture['project'],
            $changed,
            $binding->configHash,
        );
        self::assertContains('ticket_blob_changed', $reasons);
        self::assertContains('ticket_contract_changed', $reasons);
        self::assertSame(1, TicketApproval::query()->count());
        self::assertSame('queued', $fixture['approval']->refresh()->queue_state);
        self::assertNotSame($fixture['approval']->approved_ticket_blob_sha, $changed->blob_sha);
    }

    public function test_control_generation_change_is_a_read_time_start_blocker(): void
    {
        $fixture = $this->completedApproval('E2');
        self::assertSame([], $this->evaluate($fixture['approval']));

        $fixture['project']->forceFill(['control_generation' => 1])->save();
        $reasons = $this->evaluate($fixture['approval']);

        self::assertContains('control_generation_changed', $reasons);
        self::assertSame(0, $fixture['approval']->control_generation);
        self::assertSame(0, $fixture['read_model']->control_generation);
        self::assertSame('queued', $fixture['approval']->refresh()->queue_state);
    }

    public function test_dependency_changes_only_toggle_start_eligibility(): void
    {
        $fixture = $this->completedApproval('E3', '[DEP1]', 'todo');
        self::assertContains('dependency_unsatisfied:DEP1', $this->evaluate($fixture['approval']));

        $dependency = TicketReadModel::query()
            ->where('project_id', $fixture['project']->getKey())
            ->where('relative_path', 'tickets/DEP1.md')
            ->firstOrFail();
        $dependency->delete();
        $this->publishReadModel(
            $fixture['administrator'],
            $fixture['project'],
            'tickets/DEP1.md',
            $this->validTicketMarkdown('DEP1', 'done'),
        );
        self::assertSame([], $this->evaluate($fixture['approval']));

        TicketReadModel::query()
            ->where('project_id', $fixture['project']->getKey())
            ->where('relative_path', 'tickets/DEP1.md')
            ->delete();
        $this->publishReadModel(
            $fixture['administrator'],
            $fixture['project'],
            'tickets/DEP1.md',
            $this->validTicketMarkdown('DEP1', 'todo'),
        );
        self::assertContains('dependency_unsatisfied:DEP1', $this->evaluate($fixture['approval']));
        self::assertSame(1, TicketApproval::query()->count());
        self::assertSame('queued', $fixture['approval']->refresh()->queue_state);
    }

    public function test_ambiguous_dependency_ids_fail_closed_without_selecting_a_status(): void
    {
        $fixture = $this->completedApproval('E4', '[DEP1]', 'ambiguous');
        $reasons = $this->evaluate($fixture['approval']);

        self::assertContains('dependency_ambiguous:DEP1', $reasons);
        self::assertNotContains('dependency_unsatisfied:DEP1', $reasons);
        self::assertSame('queued', $fixture['approval']->refresh()->queue_state);
    }

    /**
     * @return array{
     *   administrator: User,
     *   project: Project,
     *   approval: TicketApproval,
     *   read_model: TicketReadModel,
     *   ready_content: string
     * }
     */
    private function completedApproval(string $ticketId, string $dependsOn = '[]', ?string $dependencyStatus = null): array
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        if ($dependencyStatus !== null) {
            $dependencyFixtures = $dependencyStatus === 'ambiguous'
                ? [['a', 'done'], ['b', 'todo']]
                : [['', $dependencyStatus]];
            foreach ($dependencyFixtures as [$directory, $status]) {
                $this->publishReadModel(
                    $administrator,
                    $project,
                    'tickets/'.($directory === '' ? '' : $directory.'/').'DEP1.md',
                    $this->validTicketMarkdown('DEP1', $status),
                );
            }
        }
        $todoContent = $this->validTicketMarkdown($ticketId, 'todo', $dependsOn);
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/'.$ticketId.'.md', $todoContent);
        $selection = $this->selection();
        $operationId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $project,
            $readModel,
            $selection,
            $operationId,
        );
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $todoContent,
            'Startberechtigung prüfen',
            true,
            $selection,
            $snapshot,
            $operationId,
        );
        DB::table('jobs')->delete();
        $readyContent = str_replace('status: todo', 'status: ready', $todoContent);
        self::assertSame(1, TicketApproval::query()->whereKey($operationId)->update([
            'approved_ticket_blob_sha' => $readModel->blob_sha,
            'approved_control_sha' => $project->control_oid,
            'intended_commit_sha' => $project->control_oid,
            'saga_phase' => 'complete',
            'queue_state' => 'queued',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('e', 32));
        self::assertIsInt($attemptToken);
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Approval-Operation.',
        ]);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $project->control_oid,
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => Date::now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]));
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release(
            $operation->id,
            $operation->project_id,
            $attemptToken,
        ));

        return [
            'administrator' => $administrator,
            'project' => $project->refresh(),
            'approval' => TicketApproval::query()->findOrFail($operationId),
            'read_model' => $readModel,
            'ready_content' => $readyContent,
        ];
    }

    /** @return list<string> */
    private function evaluate(TicketApproval $approval): array
    {
        $evaluation = TicketApprovalEvaluation::query()->create([
            'id' => (string) Str::uuid(),
            'ticket_approval_id' => $approval->getKey(),
            'state' => 'queued',
        ]);
        $this->app->call([new EvaluateTicketApproval($evaluation->id), 'handle']);
        $evaluation->refresh();
        self::assertSame('ready', $evaluation->state);

        return is_array($evaluation->reasons) ? $evaluation->reasons : [];
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
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            null,
            'manual',
        );
    }
}
