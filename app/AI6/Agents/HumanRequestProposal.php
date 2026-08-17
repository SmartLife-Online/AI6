<?php

namespace App\AI6\Agents;

final readonly class HumanRequestProposal
{
    /**
     * @param  list<HumanRequestOption>  $options
     * @param  list<string>  $affectedPaths
     * @param  list<string>  $criterionRefs
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $message,
        public string $whyNeeded,
        public string $responseMode,
        public array $options,
        public ?string $recommendedOption,
        public array $affectedPaths,
        public array $criterionRefs,
    ) {}
}
