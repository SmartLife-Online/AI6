<?php

namespace App\AI6\Git;

final class KnownHostsVerifier
{
    /** @param list<string> $expectedFingerprints */
    public function containsPinnedHost(string $path, string $host, array $expectedFingerprints): bool
    {
        $realPath = realpath($path);
        if ($realPath === false || $realPath !== $path || is_link($path) || ! is_file($path)) {
            return false;
        }

        $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($contents)) {
            return false;
        }

        foreach ($contents as $line) {
            if (str_starts_with(ltrim($line), '#') || str_starts_with($line, '@')) {
                continue;
            }

            $fields = preg_split('/\s+/', trim($line));
            if (! is_array($fields) || count($fields) < 3) {
                continue;
            }

            [$hosts, $algorithm, $encodedKey] = $fields;
            if (! in_array($host, explode(',', $hosts), true)
                || preg_match('/\A(?:ssh-(?:ed25519|rsa)|ecdsa-sha2-nistp(?:256|384|521))\z/D', $algorithm) !== 1) {
                continue;
            }

            $key = base64_decode($encodedKey, true);
            if (! is_string($key) || base64_encode($key) !== $encodedKey) {
                continue;
            }

            $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $key, true)), '=');
            foreach ($expectedFingerprints as $expected) {
                if (hash_equals($expected, $fingerprint)) {
                    return true;
                }
            }
        }

        return false;
    }
}
