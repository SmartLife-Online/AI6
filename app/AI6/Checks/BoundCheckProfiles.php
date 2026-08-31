<?php

namespace App\AI6\Checks;

use App\AI6\Runs\Models\Run;

/** Resolve a check phase exclusively from the immutable run snapshot. */
final class BoundCheckProfiles
{
    /** @return list<string>|null */
    public function forPhase(Run $run, CheckPhase $phase): ?array
    {
        $snapshot = $run->config_snapshot;
        if (! is_array($snapshot) || ! is_array($snapshot['values']['checks'] ?? null)) {
            return null;
        }

        $configured = $snapshot['values']['checks'][$phase->value] ?? null;
        if (! is_array($configured) || ! array_is_list($configured)) {
            return null;
        }

        $profiles = [];
        foreach ($configured as $profile) {
            if (! is_string($profile) || $profile === '') {
                return null;
            }
            $profiles[] = $profile;
        }

        return array_values(array_unique($profiles));
    }
}
