<?php

namespace App\AI6\Runs;

use App\AI6\Shared\Config\ConfigurationException;

/** The trusted root of persisted run artifacts. Project or provider content cannot change it. */
final readonly class RunArtifactRoot
{
    public function __construct(public string $path) {}

    public static function fromConfiguredValues(): self
    {
        $value = config('ai6.run_artifacts.root');
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new ConfigurationException('Configuration key ai6.run_artifacts.root must be a non-empty trusted directory path.');
        }
        $normalized = str_replace('\\', '/', $value);
        if (str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
            throw new ConfigurationException('Configuration key ai6.run_artifacts.root must not traverse its parent.');
        }

        return new self($value);
    }
}
