<?php

namespace App\AI6\Shared\Redaction;

use InvalidArgumentException;

final readonly class RedactionKeyring
{
    /**
     * @param  array<string, array{version: int, key: string}>  $keys
     */
    public function __construct(
        private string $activeKeyId,
        private array $keys,
        private bool $usesApplicationKeyFallback = false,
    ) {
        if ($this->keys === [] || ! array_key_exists($this->activeKeyId, $this->keys)) {
            throw new InvalidArgumentException('The redaction keyring requires exactly one existing active key ID.');
        }
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function activeVersion(): int
    {
        return $this->keys[$this->activeKeyId]['version'];
    }

    public function activeKey(): string
    {
        return $this->keys[$this->activeKeyId]['key'];
    }

    public function usesApplicationKeyFallback(): bool
    {
        return $this->usesApplicationKeyFallback;
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }
}
