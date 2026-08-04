<?php

namespace App\AI6\Auth;

enum LoginConfirmationVerification: string
{
    case EXPIRED = 'expired';
    case INVALID = 'invalid';
    case LOCKED = 'locked';
    case SUCCESS = 'success';
    case UNAVAILABLE = 'unavailable';
}
