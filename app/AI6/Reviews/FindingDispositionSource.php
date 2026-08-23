<?php

namespace App\AI6\Reviews;

enum FindingDispositionSource: string
{
    case SERVER_REVIEW = 'server_review';
    case HUMAN_OVERRIDE = 'human_override';
}
