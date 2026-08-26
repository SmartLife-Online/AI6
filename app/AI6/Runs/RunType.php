<?php

namespace App\AI6\Runs;

enum RunType: string
{
    case IMPLEMENTATION = 'implementation';
    case REVIEW_ONLY = 'review_only';
}
