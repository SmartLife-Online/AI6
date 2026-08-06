<?php

namespace App\AI6\Git;

use Normalizer;

final class ControlOperationHasher
{
    private const DOMAIN = "AI6-CONTROL-OPERATION-V1\0";

    /** @param array<string, scalar|null> $operationParameters */
    public function hash(
        int $schemaVersion,
        string $projectIdentifier,
        ControlOperationType $operationType,
        string $actor,
        string $authorizationSnapshotJcs,
        ?string $expectedControlCommit,
        array $operationParameters,
    ): string {
        $operationParameters = $operationType->parameters($operationParameters);
        $payload = self::DOMAIN
            .$this->field((string) $schemaVersion)
            .$this->field($projectIdentifier)
            .$this->field($operationType->value)
            .$this->field($actor)
            .$this->field($authorizationSnapshotJcs, normalize: false)
            .$this->field($expectedControlCommit);

        foreach (ControlOperationType::PARAMETER_FIELDS[$operationType->value] as $parameter) {
            $payload .= $this->field($this->parameterValue($operationParameters[$parameter]));
        }

        return hash('sha256', $payload);
    }

    private function parameterValue(string|int|bool|float|null $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => throw new CanonicalRequestException('A floating-point operation parameter is not supported.'),
        };
    }

    private function field(?string $value, bool $normalize = true): string
    {
        if ($value === null) {
            return "\0";
        }

        if ($normalize) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if (! is_string($normalized)) {
                throw new CanonicalRequestException('A request field could not be normalized.');
            }
            $value = $normalized;
        }

        $length = strlen($value);
        $high = intdiv($length, 4294967296);
        $low = $length % 4294967296;

        return "\1".pack('N2', $high, $low).$value;
    }
}
