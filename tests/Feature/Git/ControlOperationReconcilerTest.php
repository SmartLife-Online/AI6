<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ControlOperationReconcilerTest extends ControlOperationTestCase
{
    public function test_healthy_queued_job_is_not_duplicated(): void
    {
        $operation = $this->queuedOperation();
        $operation->forceFill(['updated_at' => now()->subMinute()])->save();

        self::assertSame(0, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_missing_job_is_requeued_once(): void
    {
        $operation = $this->queuedOperation();
        DB::table('jobs')->delete();
        $operation->forceFill(['updated_at' => now()->subMinute()])->save();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_exhausted_job_is_detected_and_requeued_visibly(): void
    {
        $operation = $this->queuedOperation();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'App\\AI6\\Git\\Jobs\\ExecuteControlOperation '.$operation->id,
            'exception' => 'fixture',
            'failed_at' => now(),
        ]);
        $operation->forceFill(['updated_at' => now()->subMinute()])->save();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(
            'The exhausted control-operation queue job was recovered.',
            $operation->refresh()->last_error,
        );
    }

    public function test_terminal_operation_with_a_crash_left_project_lease_is_released(): void
    {
        $operation = $this->queuedOperation();
        DB::table('jobs')->delete();
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => hash('sha256', 'terminal-orphan'),
            'safe_summary' => 'Terminaler Testauftrag.',
        ]);
        $operation->forceFill([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
            'state' => ControlOperationState::FAILED,
            'completed_at' => now(),
        ])->save();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertNull($operation->project()->sole()->operation_lock_operation_id);
        self::assertSame(0, $this->app->make(ControlOperationReconciler::class)->reconcile());
    }

    private function queuedOperation(): ControlOperation
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);

        return $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
    }
}
