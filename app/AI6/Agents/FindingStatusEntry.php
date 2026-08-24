<?php

namespace App\AI6\Agents;

use App\AI6\Reviews\FindingReviewStatus;

final readonly class FindingStatusEntry
{
    public function __construct(
        public string $findingId,
        public FindingReviewStatus $status,
        public string $evidence,
    ) {}
}
