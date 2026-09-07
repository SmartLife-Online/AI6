<?php

namespace App\AI6\Agents;

use RuntimeException;

/** The transport did not extract exactly one valid answer. */
final class InvalidAgentResponse extends RuntimeException
{
    public function __construct(public readonly string $reason = 'agent_response_invalid')
    {
        parent::__construct($reason);
    }
}
