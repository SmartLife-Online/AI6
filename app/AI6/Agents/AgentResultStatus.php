<?php

namespace App\AI6\Agents;

enum AgentResultStatus: string
{
    case COMPLETED = 'completed';
    case NO_CHANGE_REQUIRED = 'no_change_required';
    case NEEDS_HUMAN = 'needs_human';
    case FAILED = 'failed';
    case NOTHING_TO_FIX = 'nothing_to_fix';
    case FINDINGS_TO_FIX = 'findings_to_fix';
    case INCONCLUSIVE = 'inconclusive';
    case CLEAR = 'clear';
    case SECURITY_FINDINGS = 'security_findings';

    /** @return list<self> */
    public static function allowedFor(AgentRole $role): array
    {
        return match ($role) {
            AgentRole::IMPLEMENTATION => [self::COMPLETED, self::NO_CHANGE_REQUIRED, self::NEEDS_HUMAN, self::FAILED],
            AgentRole::QUALITY_REVIEW => [self::NOTHING_TO_FIX, self::FINDINGS_TO_FIX, self::NEEDS_HUMAN, self::FAILED],
            AgentRole::FINDING_VERIFICATION => [self::INCONCLUSIVE, self::CLEAR, self::NEEDS_HUMAN, self::FAILED],
            AgentRole::SECURITY_REVIEW => [self::CLEAR, self::SECURITY_FINDINGS, self::NEEDS_HUMAN, self::INCONCLUSIVE],
        };
    }
}
