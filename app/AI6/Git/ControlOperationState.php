<?php

namespace App\AI6\Git;

enum ControlOperationState: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case RECOVERY_REQUIRED = 'recovery_required';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case ABANDONED = 'abandoned';

    public function terminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::ABANDONED], true);
    }
}
