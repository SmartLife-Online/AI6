<?php

namespace App\AI6\Runs;

/**
 * The four trusted retention categories of plan §10.6.
 *
 * Every stored raw record belongs to exactly one category; the category alone
 * decides its retention time and size limit.
 */
enum RetentionCategory: string
{
    case RUN_LOGS = 'run_logs';
    case AGENT_RAW_OUTPUT = 'agent_raw_output';
    case CHECK_LOGS = 'check_logs';
    case ARTIFACTS = 'artifacts';

    public static function forArtifactKind(RunArtifactKind $kind): self
    {
        return $kind === RunArtifactKind::PROVIDER_RAW ? self::AGENT_RAW_OUTPUT : self::ARTIFACTS;
    }

    /**
     * Logs are persisted cut to their size limit with a visible marker;
     * artifacts over their limit are refused instead of stored partially.
     */
    public function truncatesOversizedRecords(): bool
    {
        return $this === self::RUN_LOGS || $this === self::CHECK_LOGS;
    }
}
