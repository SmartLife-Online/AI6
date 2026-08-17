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
}
