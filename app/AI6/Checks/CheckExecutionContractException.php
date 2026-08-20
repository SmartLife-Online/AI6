<?php

namespace App\AI6\Checks;

use RuntimeException;

final class CheckExecutionContractException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The check execution document was rejected.');
    }
}
