<?php

namespace App\AI6\Shared\Process;

use JsonSerializable;

final readonly class ProcessLimitResult implements JsonSerializable
{
    public string $hash;

    public function __construct(
        public ProcessLimit $limit,
        public int $observed,
        public int $maximum,
    ) {
        if ($observed <= $maximum || $maximum < 1) {
            throw new \InvalidArgumentException('A limit result must describe an exceeded positive boundary.');
        }

        $this->hash = hash('sha256', $this->canonicalBytes());
    }

    /** @return array{schema: string, limit: string, observed: int, maximum: int, hash: string} */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 'ai6.process-limit.v1',
            'limit' => $this->limit->value,
            'observed' => $this->observed,
            'maximum' => $this->maximum,
            'hash' => $this->hash,
        ];
    }

    private function canonicalBytes(): string
    {
        return sprintf("ai6.process-limit.v1\n%s\n%d\n%d\n", $this->limit->value, $this->observed, $this->maximum);
    }
}
