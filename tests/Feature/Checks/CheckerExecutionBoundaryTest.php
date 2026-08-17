<?php

namespace Tests\Feature\Checks;

use Tests\TestCase;

final class CheckerExecutionBoundaryTest extends TestCase
{
    public function test_checker_role_has_only_its_mailbox_and_no_credentials_or_database(): void
    {
        $compose = json_decode((string) file_get_contents(base_path('docker-compose.yml')), true, 512, JSON_THROW_ON_ERROR);
        $checker = $compose['services']['checker'];
        self::assertSame('10003:10001', $checker['user']);
        self::assertSame('none', $checker['network_mode']);
        self::assertSame('checker', $checker['environment']['AI6_RUNTIME_ROLE']);
        self::assertArrayHasKey('AI6_REDACTION_KEYS', $checker['environment']);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_PINNED_HOST_KEYS', 'AI6_AGENT_EXECUTION_ROOT', 'AI6_AGENT_OUTPUT_ROOT'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $checker['environment']);
        }
        $mounts = array_column($checker['volumes'], null, 'target');
        self::assertTrue($mounts['/var/lib/ai6/checker-executions']['read_only'] ?? false);
        self::assertArrayHasKey('/var/lib/ai6/checker-outputs', $mounts);
        self::assertArrayNotHasKey('/var/lib/ai6/agent-executions', $mounts);
        self::assertArrayNotHasKey('/var/lib/ai6/agent-outputs', $mounts);
    }
}
