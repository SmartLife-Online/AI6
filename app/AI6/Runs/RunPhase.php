<?php

namespace App\AI6\Runs;

enum RunPhase: string
{
    case PREPARE = 'prepare';
    case IMPLEMENT = 'implement';
    case CHECK = 'check';
    case REVIEW = 'review';
    case FIX = 'fix';
    case FINALIZE = 'finalize';
    case SECURITY_REVIEW = 'security_review';
    case PUBLISH = 'publish';
}
