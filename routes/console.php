<?php

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
