<?php

namespace App\AI6\Runs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The resolved retention time and size bound of exactly one category. */
final readonly class RetentionLimit
{
    /**
     * The visible marker a persisted log carries when its size limit cut it.
     * It is part of the budget: a truncated record never exceeds max_bytes,
     * which is why RetentionPolicy refuses a limit smaller than the marker.
     */
    public const TRUNCATION_MARKER = ' [AI6-RETENTION-TRUNCATED]';

    public function __construct(
        public RetentionCategory $category,
        public int $maxDays,
        public int $maxBytes,
    ) {}

    public function expiresAt(CarbonInterface $createdAt): CarbonImmutable
    {
        return CarbonImmutable::instance($createdAt)->addDays($this->maxDays);
    }

    public function exceeds(int $bytes): bool
    {
        return $bytes > $this->maxBytes;
    }

    /**
     * The persisted form of an already redacted log text: unchanged within the
     * limit, otherwise cut on a UTF-8 boundary with the visible marker inside
     * the budget. The cut may well split a redaction marker; because the
     * central redaction ran over the complete text before the cut, a split
     * marker exposes no secret — only the marker's own characters.
     */
    public function truncate(string $redactedText): string
    {
        if (! $this->exceeds(strlen($redactedText))) {
            return $redactedText;
        }

        return mb_strcut($redactedText, 0, $this->maxBytes - strlen(self::TRUNCATION_MARKER), 'UTF-8').self::TRUNCATION_MARKER;
    }
}
