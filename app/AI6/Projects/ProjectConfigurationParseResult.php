<?php

namespace App\AI6\Projects;

final readonly class ProjectConfigurationParseResult
{
    /** @param list<ProjectConfigurationError> $errors */
    public function __construct(
        public ?ProjectConfiguration $configuration,
        public array $errors,
    ) {}

    public function valid(): bool
    {
        return $this->configuration instanceof ProjectConfiguration && $this->errors === [];
    }
}
