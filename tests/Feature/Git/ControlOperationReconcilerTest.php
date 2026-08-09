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
            'Der ausgeschöpfte Queue-Auftrag der Control-Operation wurde erneut zugestellt.',
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

    public function test_running_operation_lease_renewed_while_the_batch_is_processed_is_not_requeued(): void
    {
        $operation = $this->queuedOperation();
        DB::table('jobs')->delete();
        $project = $operation->project()->sole();
        $project->forceFill([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_attempt_token' => 1,
            'operation_lock_lease_expires_at' => now()->subSecond(),
            'operation_lock_heartbeat_at' => now()->subMinute(),
        ])->save();
        $operation->forceFill([
            'state' => ControlOperationState::RUNNING,
            'current_attempt_token' => 1,
        ])->save();

        // Simulates a heartbeat renewing the project's lease in between the reconciler's initial
        // candidate selection and the moment it actually re-checks this specific operation inside
        // its own transaction. The renewal must be honored: without the per-operation re-check this
        // would spuriously requeue an operation whose lease is, by the time it is processed, current.
        $renewed = false;
        ControlOperation::retrieved(function (ControlOperation $model) use (&$renewed, $operation, $project): void {
            if (! $renewed && $model->getKey() === $operation->id) {
                $renewed = true;
                $project->forceFill(['operation_lock_lease_expires_at' => now()->addMinute()])->save();
            }
        });

        try {
            self::assertSame(0, $this->app->make(ControlOperationReconciler::class)->reconcile());
            self::assertSame(0, DB::table('jobs')->count());
        } finally {
            ControlOperation::flushEventListeners();
        }
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
