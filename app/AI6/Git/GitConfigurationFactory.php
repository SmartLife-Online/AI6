<?php

namespace App\AI6\Git;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;

final class GitConfigurationFactory
{
    public function fromConfiguredValues(): GitConfiguration
    {
        $result = $this->inspect(config('ai6.git'));

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): GitConfiguration|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.git must be an array.');
        }

        $paths = [];
        foreach (['binary', 'ssh_binary', 'ssh_wrapper', 'execution_home', 'xdg_config_home', 'global_config', 'hooks_path'] as $field) {
            $value = $configuration[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
                return new ConfigurationViolation(sprintf('Configuration key ai6.git.%s must be a non-empty path.', $field));
            }
            $paths[$field] = $value;
        }

        $executablePath = $configuration['executable_path'] ?? null;
        if (! is_string($executablePath) || trim($executablePath) === '' || str_contains($executablePath, "\0")) {
            return new ConfigurationViolation('Configuration key ai6.git.executable_path must be a non-empty fixed search path.');
        }

        $hosts = $this->commaSeparated($configuration['allowed_hosts'] ?? null, 'AI6_GIT_ALLOWED_HOSTS');
        if ($hosts instanceof ConfigurationViolation) {
            return $hosts;
        }
        foreach ($hosts as $host) {
            if ($host !== strtolower($host) || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host) !== 1) {
                return new ConfigurationViolation('Configuration key AI6_GIT_ALLOWED_HOSTS contains an invalid canonical host.');
            }
        }

        $remotePaths = $this->commaSeparated($configuration['allowed_remote_paths'] ?? null, 'AI6_GIT_ALLOWED_REMOTE_PATHS');
        if ($remotePaths instanceof ConfigurationViolation) {
            return $remotePaths;
        }

        $refPatterns = $this->commaSeparated($configuration['allowed_ref_patterns'] ?? null, 'AI6_GIT_ALLOWED_REF_PATTERNS');
        if ($refPatterns instanceof ConfigurationViolation || $refPatterns === []) {
            return $refPatterns instanceof ConfigurationViolation
                ? $refPatterns
                : new ConfigurationViolation('Configuration key AI6_GIT_ALLOWED_REF_PATTERNS must contain at least one pattern.');
        }

        $pins = $this->pins($configuration['pinned_host_keys'] ?? null);
        if ($pins instanceof ConfigurationViolation) {
            return $pins;
        }

        foreach ($hosts as $host) {
            if (! isset($pins[$host]) || $pins[$host] === []) {
                return new ConfigurationViolation('Every allowed Git host requires at least one pinned host key fingerprint.');
            }
        }

        foreach (array_keys($pins) as $host) {
            if (! in_array($host, $hosts, true)) {
                return new ConfigurationViolation('A pinned Git host key names a host outside the host allowlist.');
            }
        }

        return new GitConfiguration(
            $paths['binary'],
            $paths['ssh_binary'],
            $executablePath,
            $paths['ssh_wrapper'],
            $paths['execution_home'],
            $paths['xdg_config_home'],
            $paths['global_config'],
            $paths['hooks_path'],
            $hosts,
            $remotePaths,
            $refPatterns,
            $pins,
        );
    }

    /** @return list<string>|ConfigurationViolation */
    private function commaSeparated(mixed $value, string $key): array|ConfigurationViolation
    {
        if (! is_string($value)) {
            return new ConfigurationViolation(sprintf('Configuration key %s must be a comma-separated string.', $key));
        }

        if (trim($value) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $value));
        if (in_array('', $items, true) || count($items) !== count(array_unique($items))) {
            return new ConfigurationViolation(sprintf('Configuration key %s contains an empty or duplicate entry.', $key));
        }

        return $items;
    }

    /** @return array<string, list<string>>|ConfigurationViolation */
    private function pins(mixed $value): array|ConfigurationViolation
    {
        if (! is_string($value)) {
            return new ConfigurationViolation('Configuration key AI6_GIT_PINNED_HOST_KEYS must be a comma-separated string.');
        }

        if (trim($value) === '') {
            return [];
        }

        $pins = [];
        foreach (explode(',', $value) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) {
                return new ConfigurationViolation('Configuration key AI6_GIT_PINNED_HOST_KEYS contains an invalid entry.');
            }

            [$host, $fingerprintList] = $parts;
            $host = strtolower(trim($host));
            $fingerprints = array_filter(array_map('trim', explode('|', $fingerprintList)), static fn (string $item): bool => $item !== '');
            if ($host === '' || isset($pins[$host]) || $fingerprints === []) {
                return new ConfigurationViolation('Configuration key AI6_GIT_PINNED_HOST_KEYS contains an empty or duplicate host.');
            }

            $canonicalFingerprints = [];
            foreach ($fingerprints as $fingerprint) {
                $parsed = HostKeyFingerprint::parse($fingerprint);
                $hostKeyFingerprint = $parsed['fingerprint'];

                if (! $hostKeyFingerprint instanceof HostKeyFingerprint) {
                    return new ConfigurationViolation('Configuration key AI6_GIT_PINNED_HOST_KEYS contains an invalid SHA256 fingerprint.');
                }

                $canonicalFingerprints[] = $hostKeyFingerprint->canonical();
            }

            if (count($canonicalFingerprints) !== count(array_unique($canonicalFingerprints))) {
                return new ConfigurationViolation('Configuration key AI6_GIT_PINNED_HOST_KEYS contains a duplicate fingerprint.');
            }

            $pins[$host] = $canonicalFingerprints;
        }

        return $pins;
    }
}
