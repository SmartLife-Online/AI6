<?php

namespace App\AI6\Agents;

use RuntimeException;

final class AgentResultValidationException extends RuntimeException
{
    public function __construct(public readonly AgentResultValidationError $reason)
    {
        parent::__construct('Agent result validation failed: '.$reason->value.'.');
    }
}
