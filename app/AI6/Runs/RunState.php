<?php

namespace App\AI6\Runs;

enum RunState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case WAITING = 'waiting';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
