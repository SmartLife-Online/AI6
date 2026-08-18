<?php

namespace App\AI6\Agents;

final readonly class ImplementationSummary
{
    /**
     * @param  list<string>  $changedComponents
     * @param  list<string>  $decisions
     * @param  list<string>  $assumptions
     * @param  list<string>  $deviations
     * @param  list<string>  $knownLimits
     * @param  list<string>  $tests
     * @param  list<string>  $reviewFocus
     */
    public function __construct(
        public array $changedComponents,
        public array $decisions,
        public array $assumptions,
        public array $deviations,
        public array $knownLimits,
        public array $tests,
        public array $reviewFocus,
    ) {}
}
