<?php

namespace App\AI6\Checks;

use RuntimeException;

/** A named, value-free configuration error of one check profile. */
final class CheckProfileConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
