<?php

namespace App\AI6\Auth;

enum LoginCompletionOutcome: string
{
    case AUTHORIZED = 'authorized';
    case EMAIL_FAILED = 'email_failed';
    case EMAIL_PENDING = 'email_pending';
}
