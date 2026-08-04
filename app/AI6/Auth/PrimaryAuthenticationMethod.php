<?php

namespace App\AI6\Auth;

enum PrimaryAuthenticationMethod: string
{
    case PASSKEY = 'passkey';
    case RECOVERY = 'recovery';
    case TOTP = 'totp';
}
