<?php

namespace App\AI6\Auth;

use RuntimeException;

final class TotpSecretAuthenticationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The encrypted TOTP secret failed authenticity verification.');
    }
}
