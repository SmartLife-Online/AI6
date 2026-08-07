<?php

namespace Tests\Unit\Projects;

use App\AI6\Projects\Models\Project;
use PHPUnit\Framework\TestCase;

final class ProjectMassAssignmentGuardTest extends TestCase
{
    public function test_operation_lock_columns_are_not_mass_assignable(): void
    {
        $project = new Project;
        $project->fill([
            'name' => 'Beispielprojekt',
            'operation_lock_operation_id' => '123e4567-e89b-42d3-a456-426614174000',
            'operation_lock_attempt_token' => 7,
            'operation_lock_lease_expires_at' => '2026-01-01 00:00:00',
            'operation_lock_heartbeat_at' => '2026-01-01 00:00:00',
        ]);

        self::assertSame('Beispielprojekt', $project->name);
        self::assertNull($project->operation_lock_operation_id);
        self::assertNull($project->getAttribute('operation_lock_attempt_token'));
        self::assertNull($project->operation_lock_lease_expires_at);
        self::assertNull($project->operation_lock_heartbeat_at);

        foreach ([
            'operation_lock_operation_id',
            'operation_lock_attempt_token',
            'operation_lock_lease_expires_at',
            'operation_lock_heartbeat_at',
        ] as $lockField) {
            self::assertFalse($project->isFillable($lockField), $lockField.' must not be mass-assignable.');
        }
    }
}
