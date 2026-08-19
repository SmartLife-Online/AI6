<?php

namespace App\AI6\Runs;

enum ExecutionStepType: string
{
    case PREFLIGHT = 'preflight';
    case IMPLEMENT = 'implement';
    case CHECK = 'check';

    /**
     * Whether a worker handler for this step exists yet.
     *
     * A planned step without a handler is prepared and stays prepared; its
     * consumer ticket registers the handler and only then may it be delivered.
     */
    public function hasRegisteredHandler(): bool
    {
        return match ($this) {
            self::PREFLIGHT, self::IMPLEMENT, self::CHECK => true,
        };
    }
}
