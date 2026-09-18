<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentTurnResult;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\InvalidAgentResponse;
use Closure;

/** A missing answer produced after the real agent credential boundary. */
final class MissingAnswerAdapter implements AgentAdapter
{
    public function result(AgentResultContext $context): string
    {
        return '{}';
    }

    public function turn(AgentResultContext $context, ExecutionHome $home, Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
    {
        $heartbeat();

        throw new InvalidAgentResponse('agent_response_missing');
    }
}
