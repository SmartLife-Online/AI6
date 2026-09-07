<?php

namespace App\AI6\Agents;

use RuntimeException;

/** A value-free transport or execution boundary failure. */
final class AgentExecutionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
