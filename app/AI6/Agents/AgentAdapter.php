<?php

namespace App\AI6\Agents;

interface AgentAdapter
{
    public function result(AgentResultContext $context): string;
}
