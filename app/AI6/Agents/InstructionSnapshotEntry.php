<?php

namespace App\AI6\Agents;

use JsonSerializable;

final readonly class InstructionSnapshotEntry implements JsonSerializable
{
    public string $contentSha256;

    /** @param list<string> $imports */
    public function __construct(
        public string $discoveryName,
        public string $scope,
        public int $priority,
        public string $repositoryPath,
        public string $blobSha,
        public string $effectiveContent,
        public array $imports,
    ) {
        $this->contentSha256 = hash('sha256', $effectiveContent);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'discovery_name' => $this->discoveryName,
            'scope' => $this->scope,
            'priority' => $this->priority,
            'repository_path' => $this->repositoryPath,
            'blob_sha' => $this->blobSha,
            'effective_content' => $this->effectiveContent,
            'content_sha256' => $this->contentSha256,
            'imports' => $this->imports,
        ];
    }
}
