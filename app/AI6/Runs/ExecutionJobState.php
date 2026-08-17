<?php

namespace App\AI6\Runs;

enum ExecutionJobState: string
{
    case PLANNED = 'planned';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case WAITING = 'waiting';
    case FAILED = 'failed';
}
