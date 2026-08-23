<?php

namespace App\AI6\Reviews;

enum FindingSeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case MUST_FIX = 'must_fix';
    case HUMAN_REQUIRED = 'human_required';
    case SUGGESTION = 'suggestion';
    case FOLLOW_UP = 'follow_up';
}
