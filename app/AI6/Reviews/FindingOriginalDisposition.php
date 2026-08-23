<?php

namespace App\AI6\Reviews;

enum FindingOriginalDisposition: string
{
    case OPEN = 'open';
    case MUST_FIX = 'must_fix';
    case HUMAN_REQUIRED = 'human_required';
    case SUGGESTION = 'suggestion';
    case FOLLOW_UP = 'follow_up';
}
