<?php

namespace App\AI6\Shared\Redaction;

use InvalidArgumentException;

final readonly class RedactionContext
{
    public function __construct(
        public string $projectId,
        public ?string $runId,
        public string $identifier,
    ) {
        $this->assertIdentifier('project ID', $this->projectId);
        $this->assertIdentifier('context identifier', $this->identifier);

        if ($this->runId !== null) {
            $this->assertIdentifier('run ID', $this->runId);
        }
    }

    private function assertIdentifier(string $name, string $value): void
    {
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The redaction %s is invalid.', $name));
        }
    }
}
