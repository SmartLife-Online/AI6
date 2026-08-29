<?php

namespace App\AI6\Agents;

enum FindingVerificationRecommendation: string
{
    case CONFIRM = 'confirm';
    case NOT_APPLICABLE = 'not_applicable';
    case INVESTIGATE = 'investigate';
}
