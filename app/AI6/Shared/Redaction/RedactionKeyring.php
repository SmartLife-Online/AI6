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

    /** Whether the ring still holds the named key — active or retired. */
    public function has(string $keyId): bool
    {
        return array_key_exists($keyId, $this->keys);
    }

    /**
     * The version bound to a key of the ring; a retired key keeps its version
     * so a fingerprint written under it stays verifiable.
     */
    public function versionOf(string $keyId): int
    {
        return $this->entry($keyId)['version'];
    }

    public function keyOf(string $keyId): string
    {
        return $this->entry($keyId)['key'];
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

    /** @return array{version: int, key: string} */
    private function entry(string $keyId): array
    {
        if (! array_key_exists($keyId, $this->keys)) {
            throw new InvalidArgumentException('The redaction keyring does not hold the requested key ID.');
        }

        return $this->keys[$keyId];
    }
}
