<?php

namespace App\AI6\Shared\Security;

enum SecurityControlGroup: string
{
    case LOGIN_EMAIL_CONFIRMATION = 'login_email_confirmation';
    case PRIVILEGED_AUTHENTICATION = 'privileged_authentication';
    case HTTPS_OR_PRIVATE_ACCESS = 'https_or_private_access';
    case EXECUTION_ISOLATION = 'execution_isolation';
    case LLM_PRECOMMIT_REVIEW = 'llm_precommit_review';
}
