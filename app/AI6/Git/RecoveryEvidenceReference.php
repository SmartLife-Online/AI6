<?php

namespace App\AI6\Git;

use JsonException;

final readonly class RecoveryEvidenceReference
{
    /** @param array<string, int|string|null> $value */
    private function __construct(private array $value) {}

    /**
     * @param  null|array{commit?: string|null, base_commit?: string|null, diff_sha256?: string|null}  $input
     */
    public static function bind(
        ?array $input,
        int $projectId,
        string $operationId,
        string $findingHash,
    ): ?self {
        if ($input === null) {
            return null;
        }

        $commit = self::clean($input['commit'] ?? null);
        $baseCommit = self::clean($input['base_commit'] ?? null);
        $diffHash = self::clean($input['diff_sha256'] ?? null);
        if ($commit === null && $baseCommit === null && $diffHash === null) {
            return null;
        }
        $commitBound = $commit !== null && $baseCommit === null && $diffHash === null;
        $diffBound = $commit === null && $baseCommit !== null && $diffHash !== null;
        if (! $commitBound && ! $diffBound) {
            throw new ControlOperationConflict(
                'Recovery evidence requires either one commit or a base commit with a complete diff hash.',
            );
        }
        if (($commit !== null && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1)
            || ($baseCommit !== null && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $baseCommit) !== 1)
            || ($diffHash !== null && preg_match('/\A[0-9a-f]{64}\z/D', $diffHash) !== 1)) {
            throw new ControlOperationConflict('Recovery evidence contains an invalid Git or diff binding.');
        }

        return new self([
            'schema' => 'ai6.control-recovery-evidence.v1',
            'project_id' => $projectId,
            'operation_id' => $operationId,
            'finding_hash' => $findingHash,
            'commit' => $commit,
            'base_commit' => $baseCommit,
            'diff_sha256' => $diffHash,
        ]);
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ControlOperationConflict('Recovery evidence could not be encoded.', previous: $exception);
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
