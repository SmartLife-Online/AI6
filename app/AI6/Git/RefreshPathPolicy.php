<?php

namespace App\AI6\Git;

use Normalizer;

final readonly class RefreshPathPolicy
{
    public function __construct(private ControlOperationConfiguration $configuration) {}

    public function basePath(): string
    {
        return $this->configuration->refreshBasePath;
    }

    public function canonicalizeCandidate(string $candidate): string
    {
        $normalized = self::normalize($candidate);
        if ($normalized === null
            || $normalized === $this->configuration->refreshBasePath
            || ! str_starts_with($normalized, $this->configuration->refreshBasePath.'/')) {
            throw new ControlOperationConflict('The refresh path is outside the configured base path.');
        }

        return $normalized;
    }

    public static function canonicalBasePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        return self::normalize($path);
    }

    private static function normalize(string $path): ?string
    {
        if ($path === ''
            || strlen($path) > 1024
            || preg_match('//u', $path) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || str_ends_with($path, '/')
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $path) === 1) {
            return null;
        }

        $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $normalized;
    }
}
