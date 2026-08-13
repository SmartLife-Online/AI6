<?php

namespace App\AI6\Agents;

use RuntimeException;

final class AgentProfileSelectionException extends RuntimeException
{
    public function __construct(public readonly AgentProfileSelectionError $reason)
    {
        parent::__construct('Agent profile selection failed: '.$reason->value.'.');
    }
}
