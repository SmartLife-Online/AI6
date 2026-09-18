<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Tests\TestCase;

/** External evidence after explicitly authorized interactive test-account logins. */
final class ProviderOnboardingSmokeTest extends TestCase
{
    public function test_all_first_tier_profiles_have_current_native_onboarding_evidence(): void
    {
        if (getenv('AI6_RUN_PROVIDER_ONBOARDING_SMOKE') !== '1') {
            self::markTestSkipped('Set AI6_RUN_PROVIDER_ONBOARDING_SMOKE=1 for the external onboarding proof.');
        }
        self::assertSame('1', getenv('AI6_PROVIDER_ONBOARDING_TEST_ACCESS'), 'Explicit test-account authorization is required.');
        self::assertSame('Linux', PHP_OS_FAMILY, 'The real Linux agent runtime is required.');
        self::assertSame('agent', config('ai6.runtime_role'), 'Run this smoke in the isolated test agent.');
        self::assertTrue(app()->environment('testing'), 'A dedicated test installation is required.');
        self::assertSame(SecurityProfile::STRICT, app(SecurityPolicy::class)->profile);
        $reports = app(ProviderCapabilityReport::class);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $reports->boot());
        foreach (['codex-gpt-5.6-terra', 'grok-cli-review', 'copilot-cli-review'] as $id) {
            $profile = app(AgentProfileRegistry::class)->get($id);
            foreach ($profile->roles as $role) {
                foreach ($profile->models as $model) {
                    foreach ($profile->efforts as $effort) {
                        $diagnosis = $reports->diagnosis($profile, $role, $model, $effort);
                        self::assertSame('ready', $diagnosis['status'], $id.': '.ProviderCapabilityReport::REASONS[$diagnosis['reason']]);
                        self::assertTrue(app(AgentProfileRegistry::class)->supportsProviderSelection($profile->providerProfileAlias, $role, $model, $effort));
                    }
                }
            }
        }
    }
}
