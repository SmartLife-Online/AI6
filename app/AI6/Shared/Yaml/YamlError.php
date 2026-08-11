<?php

namespace App\AI6\Shared\Yaml;

use JsonSerializable;

final readonly class YamlError implements JsonSerializable
{
    public function __construct(public string $code, public string $message) {}

    /** @return array{code: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
