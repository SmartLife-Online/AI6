<?php

namespace App\AI6\Checks;

/** The two check phases of a run (plan §6.2, §8). */
enum CheckPhase: string
{
    case BEFORE_REVIEW = 'before_review';
    case FINAL = 'final';
}
