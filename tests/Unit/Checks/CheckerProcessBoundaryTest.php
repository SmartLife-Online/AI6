<?php

namespace Tests\Unit\Checks;

use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use Tests\TestCase;

final class CheckerProcessBoundaryTest extends TestCase
{
    public function test_checker_policy_excludes_provider_git_smtp_database_and_app_credentials(): void
    {
        $policy = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY', 'AI6_AUTH_FILE'] as $forbidden) {
            self::assertNotContains($forbidden, $policy->environmentAllowlist);
        }
        self::assertContains('AI6_CHECK_PROFILE', $policy->environmentAllowlist);
    }

    public function test_checker_namespace_tooling_is_fixed_server_side_and_drops_capabilities(): void
    {
        $policy = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER);
        self::assertContains(config('ai6.checks.runtime.workspace_root'), $policy->workingRoots);
        self::assertSame('/usr/bin/unshare', config('ai6.checks.runtime.unshare_binary'));

        $wrapper = dirname(__DIR__, 3).'/app/AI6/Shared/Process/checker-process-wrapper.sh';
        $bytes = file_get_contents($wrapper);
        self::assertIsString($bytes);
        self::assertStringContainsString('/usr/bin/mount --make-rprivate /', $bytes);
        self::assertStringStartsWith("#!/usr/bin/dash\n", $bytes);
        self::assertStringContainsString('/usr/bin/find', $bytes);
        self::assertStringContainsString('--bounding-set=-all', $bytes);
        self::assertStringContainsString('exec /usr/bin/setpriv', $bytes);

        $runner = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Shared/Process/ControlProcessRunner.php');
        self::assertIsString($runner);
        self::assertStringContainsString("'--user', '--map-root-user', '--mount', '--pid'", $runner);
        self::assertStringNotContainsString('--map-current-user', $runner);

        $dockerfile = file_get_contents(dirname(__DIR__, 3).'/Dockerfile');
        self::assertIsString($dockerfile);
        foreach (['/usr/bin/unshare', '/usr/bin/mount', '/usr/bin/find', '/usr/bin/setpriv', '/usr/bin/dash', 'checker-process-wrapper.sh'] as $inventory) {
            self::assertStringContainsString($inventory, $dockerfile);
        }
    }
}
