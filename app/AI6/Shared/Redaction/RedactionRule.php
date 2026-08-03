<?php

namespace App\AI6\Shared\Redaction;

use InvalidArgumentException;

final readonly class RedactionRule
{
    public function __construct(
        public string $name,
        public RedactionMatchType $type,
        public string $pattern,
        public int $priority,
    ) {
        if ($this->name === '' || $this->priority < 1) {
            throw new InvalidArgumentException('A redaction rule requires a name and a positive priority.');
        }
    }

    public function marker(): string
    {
        return $this->type->marker();
    }
}
