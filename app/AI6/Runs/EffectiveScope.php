<?php

namespace App\AI6\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Runs\Models\ScopeDecision;

/**
 * The gebundene effective scope of a run: initial scope plus approved additions.
 *
 * This is a pure computation over already-persisted state (the ticket's initial
 * scope and the immutable {@see ScopeDecision} rows); the
 * caller binds the resulting hash to the run via
 * {@see RunOrchestrator::applyScopeDecision()} so a later check
 * can falsify a stale binding instead of trusting a recomputation.
 */
final readonly class EffectiveScope
{
    /**
     * @param  list<string>  $initialScope
     * @param  list<string>  $approvedAdditions
     * @param  list<string>  $effectiveScope
     */
    private function __construct(
        public array $initialScope,
        public array $approvedAdditions,
        public array $effectiveScope,
        public string $hash,
    ) {}

    /**
     * @param  list<string>  $initialScope
     * @param  list<string>  $approvedAdditions
     */
    public static function compute(array $initialScope, array $approvedAdditions, CanonicalJson $canonicalJson): self
    {
        $initial = array_values(array_unique($initialScope));
        sort($initial, SORT_STRING);
        $additions = array_values(array_unique($approvedAdditions));
        sort($additions, SORT_STRING);
        $effective = array_values(array_unique([...$initial, ...$additions]));
        sort($effective, SORT_STRING);

        $encoded = $canonicalJson->normalizeAndEncode([
            'initial_scope' => $initial,
            'approved_additions' => $additions,
            'effective_scope' => $effective,
        ]);
        $hash = hash('sha256', "AI6-EFFECTIVE-SCOPE-V1\0".$encoded);

        return new self($initial, $additions, $effective, $hash);
    }
}
