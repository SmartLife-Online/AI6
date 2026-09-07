<?php

namespace App\AI6\Agents;

use App\AI6\Runs\ImportLimitResult;
use RuntimeException;

final class AgentExecutionLimitReached extends RuntimeException
{
    public function __construct(public readonly ImportLimitResult $limit)
    {
        parent::__construct('agent_answer_limit_exceeded');
    }
}
