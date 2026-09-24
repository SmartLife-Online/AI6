<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Runs\RetentionPolicy;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class RetentionDoctorCheck implements DoctorCheck
{
    public function __construct(
        private StrictPositiveIntegerParser $parser,
    ) {}

    public function label(): string
    {
        return 'Retention';
    }

    public function run(): DoctorCheckResult
    {
        try {
            new RetentionPolicy($this->parser);
            $artifactRoot = RunArtifactRoot::fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Grund' => 'retention_configuration_invalid']);
        }

        $directory = $this->nearestExistingDirectory($artifactRoot->path);
        if ($directory === null || ! is_writable($directory)) {
            return new DoctorCheckResult(false, ['Grund' => 'run_artifact_root_unavailable']);
        }

        return new DoctorCheckResult(true, ['Status' => 'Retention und Artefaktwurzel geprüft']);
    }

    private function nearestExistingDirectory(string $path): ?string
    {
        $candidate = $path;
        while (! file_exists($candidate)) {
            if (is_link($candidate)) {
                return null;
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            $candidate = $parent;
        }

        return is_dir($candidate) && ! is_link($candidate) ? $candidate : null;
    }
}
