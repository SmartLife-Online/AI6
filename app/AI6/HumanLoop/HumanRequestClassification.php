<?php

namespace App\AI6\HumanLoop;

use App\AI6\Projects\ProjectAction;

final readonly class HumanRequestClassification
{
    /**
     * @param  list<string>  $allowedEffects
     */
    public function __construct(
        public string $kind,
        public string $responseMode,
        public array $allowedEffects,
        public ProjectAction $requiredAction,
        public string $requestedEffectBinding,
    ) {}
}
