<?php

namespace App\AI6\Shared\Runtime;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

final class RuntimeSelfTestCommand extends Command
{
    protected $signature = 'ai6:runtime-selftest {key=manual : Stabiler Idempotenzschlüssel für den Laufzeitnachweis}';

    protected $description = 'Stellt einen idempotenten AI6-Laufzeittestjob in die Database Queue ein.';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $jobId = Queue::connection('database')->push(new RuntimeSelfTestJob($key));

        $this->components->info(sprintf('Laufzeittestjob %s wurde in die Database Queue eingestellt.', (string) $jobId));

        return self::SUCCESS;
    }
}
