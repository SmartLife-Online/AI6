<?php

namespace App\AI6\Agents;

enum AgentProfileSelectionError: string
{
    case PROFILE_UNKNOWN = 'profile_unknown';
    case COMBINATION_NOT_ALLOWED = 'combination_not_allowed';
    case CAPABILITY_NOT_AVAILABLE = 'capability_not_available';
}
