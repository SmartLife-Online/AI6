<?php

namespace App\AI6\Agents;

enum FindingVerificationAssessment: string
{
    case CONFIRMED = 'confirmed';
    case CONTRADICTED = 'contradicted';
    case INCONCLUSIVE = 'inconclusive';
}
