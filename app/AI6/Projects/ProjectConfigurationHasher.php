<?php

namespace App\AI6\Projects;

use App\AI6\Git\CanonicalJson;

final readonly class ProjectConfigurationHasher
{
    public function __construct(private CanonicalJson $canonicalJson) {}

    public function canonicalJson(ProjectConfiguration $configuration): string
    {
        return $this->canonicalJson->normalizeAndEncode($configuration->values);
    }

    public function hash(ProjectConfiguration $configuration): string
    {
        return hash('sha256', "AI6-PROJECT-CONFIG-SNAPSHOT-V1\0".$this->canonicalJson($configuration));
    }
}
