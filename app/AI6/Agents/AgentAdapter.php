<?php

namespace App\AI6\Agents;

use Closure;

interface AgentAdapter
{
    public function result(AgentResultContext $context): string;

    /**
     * Execute the turn against the isolated tree. The worker, not the adapter, imports.
     *
     * @param  list<string>  $unreachablePaths
     */
    public function turn(AgentResultContext $context, ExecutionHome $home, Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult;
}
