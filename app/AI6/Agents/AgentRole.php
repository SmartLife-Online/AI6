<?php

namespace App\AI6\Agents;

enum AgentRole: string
{
    case IMPLEMENTATION = 'implementation';
    case QUALITY_REVIEW = 'quality_review';
    case FINDING_VERIFICATION = 'finding_verification';
    case SECURITY_REVIEW = 'security_review';
}
