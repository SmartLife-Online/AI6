<?php

namespace App\AI6\Checks;

use JsonException;

final readonly class CheckExecutionRequest
{
    public const SCHEMA = 'ai6.check-request.v1';

    /** @var list<string> */
    private const KEYS = ['schema', 'execution_id', 'run_id', 'phase', 'profile', 'source_tree_sha256', 'baseline_sha256', 'deadline_at'];

    public function __construct(
        public string $executionId,
        public string $runId,
        public CheckPhase $phase,
        public string $profile,
        public string $sourceTreeSha256,
        public string $baselineSha256,
        public int $deadlineAt,
    ) {
        if (! self::identifier($executionId) || ! self::identifier($runId)
            || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $profile) !== 1
            || ! self::digest($sourceTreeSha256) || ! self::digest($baselineSha256)
            || $deadlineAt < 1) {
            throw new CheckExecutionContractException('check_request_value_invalid');
        }
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => self::SCHEMA,
            'execution_id' => $this->executionId,
            'run_id' => $this->runId,
            'phase' => $this->phase->value,
            'profile' => $this->profile,
            'source_tree_sha256' => $this->sourceTreeSha256,
            'baseline_sha256' => $this->baselineSha256,
            'deadline_at' => $this->deadlineAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 16384) {
            throw new CheckExecutionContractException('check_request_too_large');
        }
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CheckExecutionContractException('check_request_json_invalid');
        }
        if (! is_array($value) || array_keys($value) !== self::KEYS || $value['schema'] !== self::SCHEMA) {
            throw new CheckExecutionContractException('check_request_schema_invalid');
        }
        $phase = is_string($value['phase']) ? CheckPhase::tryFrom($value['phase']) : null;
        if (! $phase instanceof CheckPhase || ! is_string($value['execution_id']) || ! is_string($value['run_id'])
            || ! is_string($value['profile']) || ! is_string($value['source_tree_sha256'])
            || ! is_string($value['baseline_sha256']) || ! is_int($value['deadline_at'])) {
            throw new CheckExecutionContractException('check_request_type_invalid');
        }

        return new self($value['execution_id'], $value['run_id'], $phase, $value['profile'], $value['source_tree_sha256'], $value['baseline_sha256'], $value['deadline_at']);
    }

    private static function identifier(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $value) === 1;
    }

    private static function digest(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
