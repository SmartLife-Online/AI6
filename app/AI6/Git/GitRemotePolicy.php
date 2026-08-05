<?php

namespace App\AI6\Git;

final class GitRemotePolicy
{
    public function __construct(
        private readonly GitConfiguration $configuration,
        private readonly KnownHostsVerifier $knownHosts,
    ) {}

    /** @throws GitRemoteRejected */
    public function validate(string $remote, string $ref, string $knownHostsPath): ValidatedGitRemote
    {
        if ($remote === '' || str_contains($remote, "\0") || preg_match('/\A(?:file|git|https?|ext)::?/i', $remote) === 1) {
            throw new GitRemoteRejected('protocol_not_allowed');
        }

        [$host, $path] = $this->parseSshRemote($remote);
        if (! in_array($host, $this->configuration->allowedHosts, true)) {
            throw new GitRemoteRejected('host_not_allowed');
        }

        if (! in_array($path, $this->configuration->allowedRemotePaths, true)) {
            throw new GitRemoteRejected('path_not_allowed');
        }

        $this->validateRef($ref);

        $pins = $this->configuration->pinnedHostKeyFingerprints[$host] ?? [];
        if ($pins === [] || ! $this->knownHosts->containsPinnedHost($knownHostsPath, $host, $pins)) {
            throw new GitRemoteRejected('host_key_not_pinned');
        }

        return new ValidatedGitRemote($remote, $host, $path, $ref);
    }

    /** @throws GitRemoteRejected */
    public function validateRef(string $ref): void
    {
        if (! $this->validRef($ref)) {
            throw new GitRemoteRejected('ref_not_allowed');
        }
    }

    /** @return array{string, string} */
    private function parseSshRemote(string $remote): array
    {
        if (str_starts_with(strtolower($remote), 'ssh://')) {
            $parts = parse_url($remote);
            if (! is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'ssh'
                || ! isset($parts['user'], $parts['host'], $parts['path'])
                || isset($parts['pass'], $parts['query'], $parts['fragment'], $parts['port'])) {
                throw new GitRemoteRejected('ssh_remote_invalid');
            }

            $user = (string) $parts['user'];
            $host = strtolower((string) $parts['host']);
            $path = ltrim((string) $parts['path'], '/');
        } elseif (preg_match('/\A([A-Za-z_][A-Za-z0-9_-]*)@([A-Za-z0-9.-]+):(.+)\z/D', $remote, $matches) === 1) {
            $user = $matches[1];
            $host = strtolower($matches[2]);
            $path = $matches[3];
        } else {
            throw new GitRemoteRejected('protocol_not_allowed');
        }

        if ($user !== 'git'
            || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host) !== 1
            || preg_match('/\A[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)+\.git\z/D', $path) !== 1
            || str_contains($path, '..')) {
            throw new GitRemoteRejected('ssh_remote_invalid');
        }

        return [$host, $path];
    }

    private function validRef(string $ref): bool
    {
        if (preg_match('/\Arefs\/(?:heads|tags)\/[A-Za-z0-9._\/-]+\z/D', $ref) !== 1
            || str_contains($ref, '..')
            || str_contains($ref, '//')
            || str_ends_with($ref, '.lock')) {
            return false;
        }

        foreach ($this->configuration->allowedRefPatterns as $pattern) {
            $regex = '/\A'.str_replace('\\*', '[A-Za-z0-9._\\/-]+', preg_quote($pattern, '/')).'\z/D';
            if (preg_match($regex, $ref) === 1) {
                return true;
            }
        }

        return false;
    }
}
