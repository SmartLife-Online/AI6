<?php

namespace App\AI6\Runs;

enum WaitReason: string
{
    case HUMAN_QUESTION = 'human_question';
    case SCOPE_APPROVAL = 'scope_approval';
    case CONTRACT_CHANGE = 'contract_change';
    case REVIEW_LIMIT = 'review_limit';
    case RESOURCE_LIMIT = 'resource_limit';
    case PROVIDER_ERROR = 'provider_error';
    case INVALID_JSON = 'invalid_json';
    case CHECK_FAILURE = 'check_failure';
    case MANUAL_GATE = 'manual_gate';
    case SECURITY_GATE = 'security_gate';
    case GIT_BASE_CHANGED = 'git_base_changed';
    case GIT_CONFLICT = 'git_conflict';
    case MANUAL_PUSH = 'manual_push';
    case MANUAL_REPORT = 'manual_report';
    case STATUS_SYNC = 'status_sync';
}
