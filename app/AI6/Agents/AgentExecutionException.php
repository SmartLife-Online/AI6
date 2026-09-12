<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\ProcessOutcome;
use RuntimeException;

/** A value-free reason with optional centrally redacted process evidence. */
final class AgentExecutionException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?ProcessOutcome $processOutcome = null, public readonly ?int $exitCode = null, public readonly ?string $diagnostic = null)
    {
        parent::__construct($reason);
    }
}
