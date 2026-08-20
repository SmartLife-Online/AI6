<?php

namespace App\AI6\Checks;

use JsonException;

final readonly class CheckExecutionResultDocument
{
    public const SCHEMA = 'ai6.check-result.v1';

    /** @var list<string> */
    private const KEYS = ['schema', 'execution_id', 'run_id', 'phase', 'profile', 'checker_boot_id', 'deadline_at', 'completed_at', 'state', 'reason', 'exit_code', 'duration_ms', 'redacted_output', 'source_tree_sha256', 'result_tree_sha256', 'baseline_sha256', 'observed_baseline_sha256', 'metadata'];

    /** @param array<mixed> $metadata */
    public function __construct(
        public string $executionId,
        public string $runId,
        public CheckPhase $phase,
        public string $profile,
        public string $checkerBootId,
        public int $deadlineAt,
        public int $completedAt,
        public CheckResultState $state,
        public ?string $reason,
        public ?int $exitCode,
        public int $durationMilliseconds,
        public string $redactedOutput,
        public string $sourceTreeSha256,
        public string $resultTreeSha256,
        public string $baselineSha256,
        public string $observedBaselineSha256,
        public array $metadata,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $executionId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $runId) !== 1
            || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $profile) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $checkerBootId) !== 1
            || $deadlineAt < 1 || $completedAt < 1
            || ($completedAt > $deadlineAt && $state !== CheckResultState::TIMED_OUT)
            || $durationMilliseconds < 0 || $durationMilliseconds > 86_400_000
            || ($reason !== null && preg_match('/\A[a-z][a-z0-9_]{0,127}\z/D', $reason) !== 1)
            || ($exitCode !== null && ($exitCode < 0 || $exitCode > 255))
            || strlen($redactedOutput) > 5_000_000
            || preg_match('/\A[0-9a-f]{64}\z/D', $sourceTreeSha256) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $resultTreeSha256) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $baselineSha256) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $observedBaselineSha256) !== 1
            || array_keys($metadata) !== ['side_effects', 'network', 'mutates']
            || ! is_bool($metadata['side_effects']) || ! is_bool($metadata['network']) || ! is_bool($metadata['mutates'])) {
            throw new CheckExecutionContractException('check_result_value_invalid');
        }
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => self::SCHEMA, 'execution_id' => $this->executionId, 'run_id' => $this->runId,
            'phase' => $this->phase->value, 'profile' => $this->profile, 'checker_boot_id' => $this->checkerBootId,
            'deadline_at' => $this->deadlineAt, 'completed_at' => $this->completedAt,
            'state' => $this->state->value, 'reason' => $this->reason,
            'exit_code' => $this->exitCode, 'duration_ms' => $this->durationMilliseconds,
            'redacted_output' => $this->redactedOutput, 'source_tree_sha256' => $this->sourceTreeSha256,
            'result_tree_sha256' => $this->resultTreeSha256, 'baseline_sha256' => $this->baselineSha256,
            'observed_baseline_sha256' => $this->observedBaselineSha256,
            'metadata' => $this->metadata,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 6_000_000) {
            throw new CheckExecutionContractException('check_result_too_large');
        }
        try {
            $v = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CheckExecutionContractException('check_result_json_invalid');
        }
        if (! is_array($v) || array_keys($v) !== self::KEYS || $v['schema'] !== self::SCHEMA) {
            throw new CheckExecutionContractException('check_result_schema_invalid');
        }
        $phase = is_string($v['phase']) ? CheckPhase::tryFrom($v['phase']) : null;
        $state = is_string($v['state']) ? CheckResultState::tryFrom($v['state']) : null;
        if (! $phase instanceof CheckPhase || ! $state instanceof CheckResultState || ! is_string($v['execution_id'])
            || ! is_string($v['run_id']) || ! is_string($v['profile']) || ! is_string($v['checker_boot_id'])
            || ! is_int($v['deadline_at']) || ! is_int($v['completed_at'])
            || (! is_string($v['reason']) && $v['reason'] !== null)
            || (! is_int($v['exit_code']) && $v['exit_code'] !== null) || ! is_int($v['duration_ms'])
            || ! is_string($v['redacted_output']) || ! is_string($v['source_tree_sha256'])
            || ! is_string($v['result_tree_sha256']) || ! is_string($v['baseline_sha256'])
            || ! is_string($v['observed_baseline_sha256']) || ! is_array($v['metadata'])) {
            throw new CheckExecutionContractException('check_result_type_invalid');
        }

        return new self($v['execution_id'], $v['run_id'], $phase, $v['profile'], $v['checker_boot_id'], $v['deadline_at'], $v['completed_at'], $state, $v['reason'], $v['exit_code'], $v['duration_ms'], $v['redacted_output'], $v['source_tree_sha256'], $v['result_tree_sha256'], $v['baseline_sha256'], $v['observed_baseline_sha256'], $v['metadata']);
    }
}
