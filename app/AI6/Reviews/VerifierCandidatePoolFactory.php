<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;

final readonly class VerifierCandidatePoolFactory
{
    public function __construct(private AgentProfileRegistry $profiles) {}

    /** @return list<VerifierCandidate> */
    public function all(): array
    {
        $candidates = [];
        foreach ($this->profiles->all() as $profile) {
            foreach ($profile->models as $model) {
                foreach ($profile->efforts as $effort) {
                    if (! $this->profiles->supportsCombination($profile->id, AgentRole::FINDING_VERIFICATION, $model, $effort)
                        || ! $profile->capabilityStatus->selectable()) {
                        continue;
                    }
                    $key = hash('sha256', implode("\0", [$profile->id, $model, $effort]));
                    $id = substr($key, 0, 8).'-'.substr($key, 8, 4).'-4'.substr($key, 13, 3).'-a'.substr($key, 17, 3).'-'.substr($key, 20, 12);
                    $candidates[] = new VerifierCandidate($id, $profile->id, $profile->providerProfileAlias, $model, $effort, [
                        'server_profile_registered',
                        'finding_verification_capability_available',
                        'finding_verification_prompt_bound',
                    ]);
                }
            }
        }
        usort($candidates, static fn (VerifierCandidate $a, VerifierCandidate $b): int => [$a->profileId, $a->model, $a->effort] <=> [$b->profileId, $b->model, $b->effort]);

        return $candidates;
    }

    /** @param list<array<string, mixed>> $values
     * @return list<VerifierCandidate>
     */
    public function fromArray(array $values): array
    {
        $candidates = [];
        $ids = [];
        foreach ($values as $value) {
            $id = $value['id'] ?? null;
            $profileId = $value['profile_id'] ?? null;
            $model = $value['model'] ?? null;
            $effort = $value['effort'] ?? null;
            if (! is_string($id) || isset($ids[$id]) || ! is_string($profileId) || ! is_string($model) || ! is_string($effort)) {
                throw new \InvalidArgumentException('Der Verifierkandidatenpool ist ungültig.');
            }
            $selection = $this->profiles->resolve($profileId, AgentRole::FINDING_VERIFICATION, $model, $effort);
            $candidates[] = new VerifierCandidate($id, $selection->profile->id, $selection->profile->providerProfileAlias, $selection->model, $selection->effort, [
                'server_profile_registered', 'finding_verification_capability_available', 'finding_verification_prompt_bound',
            ]);
            $ids[$id] = true;
        }

        return $candidates;
    }
}
