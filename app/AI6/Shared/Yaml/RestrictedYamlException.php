<?php

namespace App\AI6\Shared\Yaml;

use RuntimeException;

final class RestrictedYamlException extends RuntimeException
{
    /** @param non-empty-list<YamlError> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Restricted YAML input was rejected.');
    }
}
