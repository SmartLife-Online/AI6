<?php

namespace App\AI6\Agents;

use RuntimeException;

/** The transport did not extract exactly one valid answer. */
final class InvalidAgentResponse extends RuntimeException
{
    /**
     * A turn that ran and reported its usage before the answer contract broke
     * carries those values here, so the consumer can still store them as
     * provider-artifact metadata (AGT-010). The answer bytes never travel
     * this way: the failure stays `invalid_json` and imports nothing.
     */
    public function __construct(
        public readonly string $reason = 'agent_response_invalid',
        public readonly ?AgentTurnResult $reportedUsage = null,
    ) {
        parent::__construct($reason);
    }
}
