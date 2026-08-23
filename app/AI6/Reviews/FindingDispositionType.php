<?php

namespace App\AI6\Reviews;

enum FindingDispositionType: string
{
    case FIXED = 'fixed';
    case NOT_APPLICABLE = 'not_applicable';
    case ACCEPTED_RISK = 'accepted_risk';

    public function isHumanOverride(): bool
    {
        return $this !== self::FIXED;
    }
}
