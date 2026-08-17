<?php

namespace Tests\Feature\Agents;

use Tests\TestCase;

final class AgentExecutionBoundaryTest extends TestCase
{
    public function test_agent_role_has_only_its_mailbox_and_no_primary_or_foreign_credentials(): void
    {
        $compose = json_decode((string) file_get_contents(base_path('docker-compose.yml')), true, 512, JSON_THROW_ON_ERROR);
        $agent = $compose['services']['agent'];
        self::assertSame('10002:10001', $agent['user']);
        self::assertSame('agent', $agent['environment']['AI6_RUNTIME_ROLE']);
        self::assertSame('/var/lib/ai6/agent-executions', $agent['environment']['AI6_AGENT_EXECUTION_ROOT']);
        self::assertSame('/var/lib/ai6/agent-outputs', $agent['environment']['AI6_AGENT_OUTPUT_ROOT']);
        self::assertArrayHasKey('AI6_REDACTION_KEYS', $agent['environment']);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_PINNED_HOST_KEYS', 'AI6_CHECKER_EXECUTION_ROOT', 'AI6_CHECKER_OUTPUT_ROOT'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $agent['environment']);
        }
        $mounts = array_column($agent['volumes'], null, 'target');
        self::assertTrue($mounts['/var/lib/ai6/agent-executions']['read_only'] ?? false);
        self::assertArrayHasKey('/var/lib/ai6/agent-outputs', $mounts);
        self::assertArrayNotHasKey('/var/lib/ai6/checker-executions', $mounts);
        self::assertArrayNotHasKey('/var/lib/ai6/checker-outputs', $mounts);
    }
}
