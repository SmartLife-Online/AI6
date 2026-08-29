<?php

namespace App\AI6\Runs;

enum ExecutionStepType: string
{
    case PREFLIGHT = 'preflight';
    case REVIEW_PREPARE = 'review_prepare';
    case IMPLEMENT = 'implement';
    case CHECK = 'check';
    case REVIEW = 'review';
    case VERIFY = 'verify';
    case REPORT = 'report';
    case FIX = 'fix';

    /**
     * Whether a worker handler for this step exists yet.
     *
     * A planned step without a handler is prepared and stays prepared; its
     * consumer ticket registers the handler and only then may it be delivered.
     */
    public function hasRegisteredHandler(): bool
    {
        return match ($this) {
            self::PREFLIGHT, self::REVIEW_PREPARE, self::IMPLEMENT, self::CHECK, self::REVIEW, self::VERIFY, self::REPORT, self::FIX => true,
        };
    }
}
