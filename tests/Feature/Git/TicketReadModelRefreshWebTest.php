<?php

namespace Tests\Feature\Git;

use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TicketReadModelRefreshWebTest extends ControlOperationTestCase
{
    public function test_member_can_enqueue_exactly_one_canonical_candidate_without_running_git(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $operator = $this->createUser();
        $project = $this->registeredProject($administrator);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'control_oid' => str_repeat('a', 64),
        ])->save();
        $operationId = (string) Str::uuid();

        $response = $this->actingAs($operator)->post(route('projects.ticket-read-model.refresh', $project), [
            'operation_id' => $operationId,
            'relative_path' => 'tickets/AI6-006F.md',
        ]);

        $operation = ControlOperation::query()->sole();
        $response->assertRedirect(route('projects.operations.show', [$project, $operation]));
        self::assertSame(ControlOperationType::TICKET_REFRESH, $operation->operation_type);
        self::assertSame(str_repeat('a', 64), $operation->expected_control_commit);
        self::assertNull($operation->process_id);
        $binding = $this->app->make(EffectiveProjectConfiguration::class)->for($project->refresh());
        self::assertSame(
            [
                'effective_config_hash' => $binding->configHash,
                'refresh_base_path' => 'tickets',
                'relative_path' => 'tickets/AI6-006F.md',
                'validation_profile' => $binding->configuration->ticketValidationProfile()->value,
            ],
            json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, DB::table('jobs')->count());

        $this->actingAs($operator)->post(route('projects.ticket-read-model.refresh', $project), [
            'operation_id' => $operationId,
            'relative_path' => 'tickets/AI6-006F.md',
        ])->assertRedirect(route('projects.operations.show', [$project, $operation]));
        self::assertSame(1, ControlOperation::query()->count());
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_outside_candidate_and_non_member_create_no_operation(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $operator = $this->createUser();
        $outsider = $this->createUser();
        $viewer = $this->createUser();
        $approver = $this->createUser();
        $project = $this->registeredProject($administrator);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'control_oid' => str_repeat('a', 64),
        ])->save();

        $this->actingAs($operator)->from(route('projects.show', $project))->post(
            route('projects.ticket-read-model.refresh', $project),
            ['operation_id' => (string) Str::uuid(), 'relative_path' => 'other/AI6-006F.md'],
        )->assertRedirect(route('projects.show', $project))->assertSessionHasErrors('relative_path');

        $this->actingAs($outsider)->post(route('projects.ticket-read-model.refresh', $project), [
            'operation_id' => (string) Str::uuid(),
            'relative_path' => 'tickets/AI6-006F.md',
        ])->assertForbidden();

        foreach ([$viewer, $approver] as $readOnlyMember) {
            $this->actingAs($readOnlyMember)->post(route('projects.ticket-read-model.refresh', $project), [
                'operation_id' => (string) Str::uuid(),
                'relative_path' => 'tickets/AI6-006F.md',
            ])->assertForbidden();
        }

        self::assertSame(0, ControlOperation::query()->count());
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function test_browser_input_cannot_replace_the_server_refresh_base_path(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'control_oid' => str_repeat('a', 64),
        ])->save();

        $this->actingAs($administrator)->post(route('projects.ticket-read-model.refresh', $project), [
            'operation_id' => (string) Str::uuid(),
            'relative_path' => '.ai6/config.yaml',
            'refresh_base_path' => '.ai6',
        ])->assertSessionHasErrors('relative_path');

        self::assertSame(0, ControlOperation::query()->count());
    }
}
