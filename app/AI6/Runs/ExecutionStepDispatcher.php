<?php

namespace App\AI6\Runs;

use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use Illuminate\Support\Facades\Queue;

/** The single place that hands a planned execution step to the worker queue. */
final readonly class ExecutionStepDispatcher
{
    public function dispatch(ExecutionJob $job): void
    {
        Queue::connection('database')->push(new ExecuteRunStep($job->id));
    }
}
