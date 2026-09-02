<?php

namespace App\AI6\Runs;

enum QueueReevaluationTrigger: string
{
    case FETCH = 'fetch';
    case READ_MODEL_REFRESH = 'read_model_refresh';
    case TICKET_CHANGE = 'ticket_change';
    case CONFIG_CHANGE = 'config_change';
    case PROMPT_CHANGE = 'prompt_change';
    case CAPABILITY_CHANGE = 'capability_change';
    case SECURITY_POLICY_CHANGE = 'security_policy_change';
    case APPROVAL_REVOCATION = 'approval_revocation';
    case DEPENDENCY_STATUS_CHANGE = 'dependency_status_change';
    case RUN_COMPLETION = 'run_completion';
    case QUEUE_INTERVENTION = 'queue_intervention';
}
