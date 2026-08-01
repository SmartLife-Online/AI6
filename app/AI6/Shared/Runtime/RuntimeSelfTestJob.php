<?php

namespace App\AI6\Shared\Runtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class RuntimeSelfTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $idempotencyKey)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $directory = getenv('AI6_EXECUTION_DIRECTORY');

        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException('AI6_EXECUTION_DIRECTORY is not configured.');
        }

        (new RuntimeExecutionMark($directory))->claim($this->idempotencyKey);
    }
}
