<?php

namespace App\AI6\Agents;

final readonly class FindingVerificationResult
{
    public function __construct(
        public ?string $findingId,
        public ?string $duplicateGroup,
        public FindingVerificationAssessment $assessment,
        public FindingVerificationRecommendation $recommendation,
        public string $evidence,
    ) {}
}
