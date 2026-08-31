<?php

namespace Tests\Unit\Reviews;

use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentRole;
use Tests\TestCase;

final class SecurityReviewContractTest extends TestCase
{
    public function test_security_result_statuses_are_closed(): void
    {
        self::assertSame([
            AgentResultStatus::CLEAR,
            AgentResultStatus::SECURITY_FINDINGS,
            AgentResultStatus::NEEDS_HUMAN,
            AgentResultStatus::INCONCLUSIVE,
        ], AgentResultStatus::allowedFor(AgentRole::SECURITY_REVIEW));
        self::assertNotContains(AgentResultStatus::FAILED, AgentResultStatus::allowedFor(AgentRole::SECURITY_REVIEW));

    }
}
