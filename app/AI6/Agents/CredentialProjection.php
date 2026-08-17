<?php

namespace App\AI6\Agents;

final readonly class CredentialProjection
{
    /** @param array<string, string> $files */
    public function __construct(
        public string $providerProfileAlias,
        public string $revision,
        public array $files,
    ) {
        if ($revision === '') {
            throw new CredentialProjectionException('The bound credential revision is invalid.');
        }
        foreach ($files as $target => $source) {
            if (! $this->validTarget($target)
                || str_contains('/'.$target.'/', '/../') || ! is_file($source) || is_link($source)) {
                throw new CredentialProjectionException('The credential projection contains an invalid file.');
            }
        }
    }

    private function validTarget(string $target): bool
    {
        return $target !== '' && strlen($target) <= 256
            && ctype_alnum($target[0])
            && strspn($target, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._/-') === strlen($target);
    }
}
