<?php

use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Runs\RunRetentionSweep;
use App\AI6\Runs\RunStepReconciler;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Runtime\RuntimeSelfTestJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

Schedule::call(static function (): void {
    $directory = getenv('AI6_HEARTBEAT_DIRECTORY');

    if (! is_string($directory) || $directory === '') {
        throw new RuntimeException('AI6_HEARTBEAT_DIRECTORY is not configured.');
    }

    $heartbeat = new RuntimeHeartbeat($directory);
    $heartbeat->write('scheduler');
    Queue::connection('database')->push(new RuntimeSelfTestJob('scheduler:'.$heartbeat->bootId()));
})
    ->name('ai6-runtime-scheduler')
    ->everyTenSeconds();

Schedule::call(static function (): void {
    app(ControlOperationReconciler::class)->reconcile();
})
    ->name('ai6-control-operation-reconciler')
    ->everyTenSeconds();

Schedule::call(static function (): void {
    app(RunStepReconciler::class)->reconcile();
})
    ->name('ai6-run-step-reconciler')
    ->everyTenSeconds();

Schedule::call(static function (): void {
    app(QueueReevaluation::class)->scheduleTrustedBindingChanges();
})
    ->name('ai6-queue-trusted-binding-reevaluation')
    ->everyTenSeconds();

Schedule::call(static function (): void {
    app(RunRetentionSweep::class)->sweep();
})
    ->name('ai6-run-retention')
    ->hourly();
