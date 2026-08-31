<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionError;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\SecurityReviewerProfileResolver;
use App\AI6\Shared\Config\StrictEnumParser;
use Tests\TestCase;

final class SecurityReviewerProfileResolverTest extends TestCase
{
    public function test_only_the_trusted_instance_profile_is_resolved_for_the_security_role(): void
    {
        config(['ai6.agent_security_review_profile' => 'fake']);
        $selection = $this->app->make(SecurityReviewerProfileResolver::class)->resolve();

        self::assertSame('fake', $selection->profile->id);
        self::assertSame(AgentRole::SECURITY_REVIEW, $selection->role);
        self::assertSame('fake-model', $selection->model);
    }

    public function test_unknown_and_role_foreign_profiles_fail_closed(): void
    {
        config(['ai6.agent_security_review_profile' => 'unknown']);
        try {
            $this->app->make(SecurityReviewerProfileResolver::class)->resolve();
            self::fail('An unknown security profile unexpectedly resolved.');
        } catch (AgentProfileSelectionException $exception) {
            self::assertSame(AgentProfileSelectionError::PROFILE_UNKNOWN, $exception->reason);
        }

        $configuration = config('ai6.agent_profiles');
        self::assertIsArray($configuration);
        $configuration['fake']['roles'] = ['implementation'];
        $resolver = new SecurityReviewerProfileResolver(AgentProfileRegistry::fromArray($configuration, new StrictEnumParser));
        config(['ai6.agent_security_review_profile' => 'fake']);

        try {
            $resolver->resolve();
            self::fail('A role-foreign security profile unexpectedly resolved.');
        } catch (AgentProfileSelectionException $exception) {
            self::assertSame(AgentProfileSelectionError::COMBINATION_NOT_ALLOWED, $exception->reason);
        }
    }
}
