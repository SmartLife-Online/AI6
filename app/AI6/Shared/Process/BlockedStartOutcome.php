<?php

namespace App\AI6\Shared\Process;

enum BlockedStartOutcome: string
{
    case READY = 'ready';
    case LOCK_UNAVAILABLE = 'lock_unavailable';
    case LOCK_CONFLICT = 'lock_conflict';
    case CONFIGURATION_ERROR = 'configuration_error';
    case START_FAILED = 'start_failed';
}
