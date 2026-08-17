<?php

namespace App\AI6\Shared\Process;

enum ProcessOutcome: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';
    case OUTPUT_LIMIT_EXCEEDED = 'output_limit_exceeded';
    case RESOURCE_LIMIT_EXCEEDED = 'resource_limit_exceeded';
    case CANCELLED = 'cancelled';
    case TERMINATION_FAILED = 'termination_failed';
    case START_FAILED = 'start_failed';
    case START_REJECTED = 'start_rejected';
}
