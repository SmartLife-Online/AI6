<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;

/** Resolve the one trusted instance-level security-reviewer selection. */
final readonly class SecurityReviewerProfileResolver
{
    public function __construct(private AgentProfileRegistry $profiles) {}

    public function resolve(): AgentSelection
    {
        $profileId = config('ai6.agent_security_review_profile');
        if (! is_string($profileId) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $profileId) !== 1) {
            throw new ConfigurationException('Configuration key ai6.agent_security_review_profile is invalid.');
        }

        $profile = $this->profiles->get($profileId);
        if (! in_array(AgentRole::SECURITY_REVIEW, $profile->roles, true)) {
            throw new AgentProfileSelectionException(AgentProfileSelectionError::COMBINATION_NOT_ALLOWED);
        }

        return $this->profiles->resolve(
            $profileId,
            AgentRole::SECURITY_REVIEW,
            $profile->models[0],
            $profile->efforts[0],
        );
    }
}
