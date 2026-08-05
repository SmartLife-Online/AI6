<?php

namespace App\AI6\Shared\Process;

enum EffectLockOutcome: string
{
    case ACQUIRED = 'acquired';
    case UNAVAILABLE = 'unavailable';
    case CONFIGURATION_ERROR = 'configuration_error';
    case CONFLICT = 'conflict';
}
