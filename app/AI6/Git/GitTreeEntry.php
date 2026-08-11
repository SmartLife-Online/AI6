<?php

namespace App\AI6\Git;

final readonly class GitTreeEntry
{
    public function __construct(
        public string $name,
        public string $mode,
        public string $type,
        public string $objectId,
    ) {}

    public function isRegularBlob(): bool
    {
        return $this->type === 'blob' && in_array($this->mode, ['100644', '100755'], true);
    }
}
