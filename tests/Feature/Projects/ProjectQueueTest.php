<?php

namespace Tests\Feature\Projects;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Git\ControlOperationType;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\QueueAutoStarter;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ProjectQueueTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_queue_view_and_actions_are_authorized_version_bound_and_mobile_safe(): void
    {
        $fixture = $this->completedApproval('QUEUE-1');
        $approval = $fixture['approval'];
        TicketApprovalEvaluation::query()->create([
            'id' => (string) Str::uuid(),
            'ticket_approval_id' => $approval->id,
            'state' => 'ready',
            'eligible' => true,
            'reasons' => [],
        ]);
        $viewer = $this->createUser();
        $this->addMembership($viewer, $fixture['project'], ProjectRole::VIEWER);

        $response = $this->actingAs($viewer)->get(route('projects.queue.index', $fixture['project']));
        $response->assertOk()
            ->assertSee('Projektqueue')
            ->assertSee('QUEUE-1')
            ->assertSee('Nächstes startbares Ticket')
            ->assertSee('Blockierende Gründe')
            ->assertDontSee('style=');
        self::assertStringNotContainsString("'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));

        $this->actingAs($viewer)->post(route('projects.queue.remove', [$fixture['project'], $approval]), [
            'expected_version' => $approval->version,
        ])->assertForbidden();

        $this->actingAs($fixture['operator'])->post(route('projects.queue.remove', [$fixture['project'], $approval]), [
            'expected_version' => $approval->version,
        ])->assertRedirect(route('projects.queue.index', $fixture['project']));
        $approval->refresh();
        self::assertSame('available', $approval->queue_state);
        self::assertNull($approval->queued_at);

        $this->actingAs($fixture['operator'])->post(route('projects.queue.enqueue', [$fixture['project'], $approval]), [
            'expected_version' => $approval->version - 1,
        ])->assertSessionHasErrors('queue');
        self::assertSame('available', $approval->fresh()->queue_state);

        $this->actingAs($fixture['operator'])->post(route('projects.queue.enqueue', [$fixture['project'], $approval]), [
            'expected_version' => $approval->version,
        ])->assertRedirect(route('projects.queue.index', $fixture['project']));
        $approval->refresh();
        self::assertSame('queued', $approval->queue_state);
        self::assertNotNull($approval->queued_at);
        self::assertSame(0, DB::table('runs')->count());
    }

    public function test_disabled_auto_start_keeps_the_queue_unchanged(): void
    {
        $origin = $this->completedApproval('QUEUE-OFF-ORIGIN-1');
        $completedRun = $this->markCompleted($this->finalizedRun($origin));
        $fixture = $this->completedApproval('QUEUE-OFF-1', $origin['project']->refresh(), $origin['operator']);
        $approval = $fixture['approval'];

        self::assertNull($this->app->make(QueueAutoStarter::class)->afterCompletion($fixture['project'], $completedRun));
        self::assertSame('queued', TicketApproval::query()->findOrFail($approval->id)->queue_state);
        self::assertSame(1, DB::table('runs')->count());
    }

    public function test_enabled_auto_start_reserves_at_most_one_queue_entry(): void
    {
        config()->set('ai6.project_config.server_defaults.auto_start_next', true);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $origin = $this->completedApproval('QUEUE-ON-ORIGIN-1');
        $completedRun = $this->markCompleted($this->finalizedRun($origin));
        $fixture = $this->completedApproval('QUEUE-ON-1', $origin['project']->refresh(), $origin['operator']);

        $starter = $this->app->make(QueueAutoStarter::class);
        $operation = $starter->afterCompletion($fixture['project'], $completedRun);

        self::assertNotNull($operation);
        self::assertSame(ControlOperationType::RUN_START, $operation->operation_type);
        self::assertSame($origin['operator']->id, $operation->actor_id);
        self::assertSame(
            'approval_auto_start',
            json_decode($operation->authorization_snapshot_jcs, true, flags: JSON_THROW_ON_ERROR)['authorization'],
        );
        self::assertNull($starter->afterCompletion($fixture['project']->refresh(), $completedRun));
        self::assertSame(1, DB::table('control_operations')->where('id', $operation->id)->count());
        self::assertSame(1, DB::table('runs')->count());
    }

    public function test_auto_start_skips_a_blocked_head_and_reserves_the_next_entry(): void
    {
        config()->set('ai6.project_config.server_defaults.auto_start_next', true);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $origin = $this->completedApproval('QUEUE-SKIP-ORIGIN-1');
        $completedRun = $this->markCompleted($this->finalizedRun($origin));
        $blocked = $this->completedApproval('QUEUE-BLOCKED-1', $origin['project']->refresh(), $origin['operator']);
        config()->set('ai6.project_config.server_defaults.dependency_satisfied_statuses', ['done', 'review']);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        $next = $this->completedApproval('QUEUE-NEXT-1', $blocked['project'], $blocked['operator']);

        $operation = $this->app->make(QueueAutoStarter::class)->afterCompletion($blocked['project'], $completedRun);

        self::assertNotNull($operation);
        self::assertSame($next['approval']->id, json_decode($operation->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR)['approval_id']);
        self::assertSame('queued', $blocked['approval']->fresh()->queue_state);
        self::assertSame(1, DB::table('control_operations')->where('id', $operation->id)->count());
    }

    private function markCompleted(Run $run): Run
    {
        self::assertSame(1, Run::query()->whereKey($run->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($run->project_id)->update(['active_run_id' => null]));

        return $run->refresh();
    }
}
