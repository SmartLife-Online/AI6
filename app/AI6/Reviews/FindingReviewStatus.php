<?php

namespace App\AI6\Reviews;

enum FindingReviewStatus: string
{
    case FIXED = 'fixed';
    case PARTIALLY_FIXED = 'partially_fixed';
    case NOT_FIXED = 'not_fixed';
    case NOT_APPLICABLE = 'not_applicable';
}
