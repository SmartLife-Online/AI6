<?php

namespace App\AI6\Agents;

use RuntimeException;

final class InstructionResolutionException extends RuntimeException
{
    public function __construct(public readonly InstructionResolutionError $reason)
    {
        parent::__construct('Instruction resolution failed: '.$reason->value.'.');
    }
}
