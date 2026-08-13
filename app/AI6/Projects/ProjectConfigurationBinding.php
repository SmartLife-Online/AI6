<?php

namespace App\AI6\Projects;

final readonly class ProjectConfigurationBinding
{
    public function __construct(
        public ProjectConfigurationSource $source,
        public ProjectConfiguration $configuration,
        public string $configHash,
        public ?string $blobSha,
        public ?string $snapshotId,
        public int $controlGeneration,
    ) {}
}
