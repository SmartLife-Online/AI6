<?php

namespace App\AI6\Git\Jobs;

use App\AI6\Git\ControlOperationExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExecuteControlOperation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $operationId)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(ControlOperationExecutor $executor): void
    {
        $executor->execute($this->operationId);
    }
}
