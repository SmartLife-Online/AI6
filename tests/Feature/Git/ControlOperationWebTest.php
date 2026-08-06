<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\ProjectRole;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Str;

final class ControlOperationWebTest extends ControlOperationTestCase
{
    public function test_global_project_administrator_can_enqueue_without_executing_a_process_in_the_request(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);

        $response = $this->actingAs($administrator)->post(route('projects.deploy-key.provision', $project), [
            'operation_id' => (string) Str::uuid(),
        ]);

        $operation = ControlOperation::query()->sole();
        $response->assertRedirect(route('projects.operations.show', [$project, $operation]));
        self::assertNull($operation->process_id);
        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee($operation->id)
            ->assertSee('queued');
    }

    public function test_viewer_and_non_member_are_denied_without_operation_content(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $outsider = $this->createUser();
        $project = $this->registeredProject($administrator);
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);

        foreach ([$viewer, $outsider] as $user) {
            $this->actingAs($user)
                ->post(route('projects.deploy-key.provision', $project), ['operation_id' => (string) Str::uuid()])
                ->assertForbidden()
                ->assertDontSee($project->name);
        }

        self::assertSame(0, ControlOperation::query()->count());
    }

    public function test_operation_view_shows_only_redacted_error_and_safe_result_summary(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $redacted = $this->app->make(Redactor::class)->redact(
            'password=hunter2',
            new RedactionContext((string) $project->getKey(), $operation->id, 'operation-web-test'),
        )->text;
        $operation->forceFill(['last_error' => $redacted])->save();
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => hash('sha256', 'web-result'),
            'safe_summary' => 'Sicher zusammengefasst.',
        ]);

        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee('password=[REDACTED:SECRET]')
            ->assertSee('Sicher zusammengefasst.')
            ->assertDontSee('hunter2');
    }
}
