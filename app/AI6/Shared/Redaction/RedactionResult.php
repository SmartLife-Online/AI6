<?php

namespace App\AI6\Shared\Redaction;

use JsonSerializable;

final readonly class RedactionResult implements JsonSerializable
{
    /** @param list<RedactionMatch> $matches */
    public function __construct(
        public string $text,
        public array $matches,
    ) {}

    /** @return array{text: string, matches: list<RedactionMatch>} */
    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'matches' => $this->matches,
        ];
    }
}
