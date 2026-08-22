<?php

namespace App\AI6\Agents;

enum AgentScenario: string
{
    case SUCCESS = 'success';
    case NO_CHANGE_REQUIRED = 'no_change_required';
    case NO_CHANGE_WITH_DIFF = 'no_change_with_diff';
    case HUMAN_REQUEST = 'human_request';
    case FINDINGS = 'findings';
    case INVALID_JSON = 'invalid_json';
    case PROVIDER_ERROR = 'provider_error';
    case SECURITY_FINDINGS = 'security_findings';
    case UNTRUSTED_EVIDENCE = 'untrusted_evidence';
}
