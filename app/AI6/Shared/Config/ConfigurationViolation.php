<?php

namespace App\AI6\Shared\Config;

final readonly class ConfigurationViolation
{
    public function __construct(public string $message) {}
}
