<?php

namespace App\AI6\Reviews;

final class VerifierSlotSelector
{
    /**
     * @param  list<array<string, mixed>>  $boundCandidates
     * @param  list<string>  $sourceProviderProfiles
     * @param  list<string>  $forbiddenProfileIds
     */
    public function select(array $boundCandidates, array $sourceProviderProfiles, array $forbiddenProfileIds): ?VerifierCandidate
    {
        foreach ($boundCandidates as $candidate) {
            $values = [
                $candidate['id'] ?? null,
                $candidate['profile_id'] ?? null,
                $candidate['provider_profile'] ?? null,
                $candidate['model'] ?? null,
                $candidate['effort'] ?? null,
            ];
            if (! array_reduce($values, static fn (bool $valid, mixed $value): bool => $valid && is_string($value) && $value !== '', true)
                || ($candidate['prompt_profile_id'] ?? null) !== 'finding_verification'
                || in_array($values[2], $sourceProviderProfiles, true)
                || in_array($values[1], $forbiddenProfileIds, true)) {
                continue;
            }

            return new VerifierCandidate($values[0], $values[1], $values[2], $values[3], $values[4], [
                'selected_from_approval_snapshot',
                'source_provider_profile_excluded',
                'implementation_and_fix_profiles_excluded',
            ]);
        }

        return null;
    }
}
