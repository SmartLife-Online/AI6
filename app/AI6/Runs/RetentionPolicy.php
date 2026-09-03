<?php

namespace App\AI6\Runs;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one resolution of the trusted retention values (SEC-011, plan §10.6).
 *
 * Every value comes from `config('ai6.retention')`, which only trusted instance
 * configuration writes. The approved project configuration, the run snapshots
 * and provider output are never consulted, so none of them can set or extend a
 * retention time or a size limit. A missing or invalid value ends the
 * resolution with a named configuration error that names the key, never the
 * read value.
 */
final readonly class RetentionPolicy
{
    private const MAX_DAYS = 3650;

    private const MAX_BYTES = 1_000_000_000_000;

    /** @var array<string, RetentionLimit> */
    private array $limits;

    public int $activeRunGraceDays;

    public function __construct(StrictPositiveIntegerParser $parser)
    {
        $values = config('ai6.retention');
        $expectedKeys = [...array_map(static fn (RetentionCategory $category): string => $category->value, RetentionCategory::cases()), 'active_run_grace_days'];
        if (! is_array($values) || array_keys($values) !== $expectedKeys) {
            throw new ConfigurationException('Configuration key ai6.retention must contain exactly the categories run_logs, agent_raw_output, check_logs, artifacts and active_run_grace_days.');
        }

        $limits = [];
        foreach (RetentionCategory::cases() as $category) {
            $entry = $values[$category->value];
            if (! is_array($entry) || array_keys($entry) !== ['max_days', 'max_bytes']) {
                throw new ConfigurationException('Configuration key ai6.retention.'.$category->value.' must contain exactly max_days and max_bytes.');
            }
            $maxBytes = self::parse($parser, 'ai6.retention.'.$category->value.'.max_bytes', $entry['max_bytes'], self::MAX_BYTES);
            // A log cut to its limit still carries the visible truncation
            // marker inside that limit; a budget the marker alone would
            // exceed can never be honored and is refused by name.
            if ($category->truncatesOversizedRecords() && $maxBytes <= strlen(RetentionLimit::TRUNCATION_MARKER)) {
                throw new ConfigurationException('Configuration key ai6.retention.'.$category->value.'.max_bytes must exceed the length of the truncation marker ('.strlen(RetentionLimit::TRUNCATION_MARKER).' bytes).');
            }
            // The checkpoint diff shares the artifact budget with its binding
            // header; a budget the header alone would exceed could store no
            // diff of any checkpoint and is refused by name.
            if ($category === RetentionCategory::ARTIFACTS && $maxBytes < CheckpointDiffRecorder::HEADER_MAX_BYTES) {
                throw new ConfigurationException('Configuration key ai6.retention.'.$category->value.'.max_bytes must be at least the checkpoint diff header size ('.CheckpointDiffRecorder::HEADER_MAX_BYTES.' bytes).');
            }
            $limits[$category->value] = new RetentionLimit(
                $category,
                self::parse($parser, 'ai6.retention.'.$category->value.'.max_days', $entry['max_days'], self::MAX_DAYS),
                $maxBytes,
            );
        }

        $this->limits = $limits;
        $this->activeRunGraceDays = self::parse($parser, 'ai6.retention.active_run_grace_days', $values['active_run_grace_days'], self::MAX_DAYS);
    }

    public function limit(RetentionCategory $category): RetentionLimit
    {
        return $this->limits[$category->value];
    }

    public function artifactLimit(RunArtifactKind $kind): RetentionLimit
    {
        return $this->limit(RetentionCategory::forArtifactKind($kind));
    }

    /**
     * The moment after which expired data of a run may actually be removed.
     *
     * An active run defers the removal by the central grace period and by
     * nothing else; a finished run defers nothing.
     */
    public function purgeDeadline(CarbonInterface $expiresAt, bool $runActive): CarbonImmutable
    {
        $deadline = CarbonImmutable::instance($expiresAt);

        return $runActive ? $deadline->addDays($this->activeRunGraceDays) : $deadline;
    }

    public static function runIsActive(Run $run): bool
    {
        return in_array($run->state, [RunState::QUEUED, RunState::RUNNING, RunState::WAITING], true);
    }

    private static function parse(StrictPositiveIntegerParser $parser, string $key, mixed $value, int $maximum): int
    {
        $parsed = $parser->parse($key, $value, $maximum);
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }
}
