<?php

namespace App\AI6\HumanLoop;

enum HumanRequestResolutionState: string
{
    case ANSWERED = 'answered';
    case CANCELLED = 'cancelled';
    case OPEN = 'open';
}
