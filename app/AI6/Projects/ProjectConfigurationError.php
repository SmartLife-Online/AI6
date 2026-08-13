<?php

namespace App\AI6\Projects;

final readonly class ProjectConfigurationError implements \JsonSerializable
{
    public function __construct(
        public string $code,
        public string $field,
        public string $message,
    ) {}

    /** @return array{code: string, field: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'field' => $this->field, 'message' => $this->message];
    }
}
