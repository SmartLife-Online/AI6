<?php

namespace App\AI6\Agents;

enum AgentResultValidationError: string
{
    case SCHEMA = 'schema';
    case BINDING = 'binding';
    case STATUS = 'status';
    case CRITERION_REFERENCE = 'criterion_reference';
    case NO_CHANGE_DIFF = 'no_change_diff';
    case INSTRUCTION_PATCH = 'instruction_patch';
    case REDACTED_INSTRUCTION_PATCH = 'redacted_instruction_patch';
}
