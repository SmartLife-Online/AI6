<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;

final readonly class CredentialRevisionRegistry
{
    /** @param array<string, string> $revisions */
    public function __construct(private array $revisions) {}

    public static function fromConfiguredValues(): self
    {
        $values = config('ai6.credential_revisions');
        if (! is_array($values)) {
            throw new ConfigurationException('Configuration key ai6.credential_revisions must be an object.');
        }
        foreach ($values as $alias => $revision) {
            if (! is_string($alias) || ! is_string($revision)) {
                throw new ConfigurationException('A credential revision entry is invalid.');
            }
        }

        return new self($values);
    }

    public function assertCurrent(CredentialProjection $projection): void
    {
        $current = $this->revisions[$projection->providerProfileAlias] ?? null;
        if (! is_string($current) || $current === '' || ! hash_equals($current, $projection->revision)) {
            throw new CredentialProjectionException('The bound credential revision is no longer current.');
        }
    }

    public function revision(string $providerProfileAlias): string
    {
        $revision = $this->revisions[$providerProfileAlias] ?? null;
        if (! is_string($revision) || $revision === '') {
            throw new CredentialProjectionException('The provider credential revision is unavailable.');
        }

        return $revision;
    }
}
