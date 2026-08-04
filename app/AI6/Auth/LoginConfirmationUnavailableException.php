<?php

namespace App\AI6\Auth;

use RuntimeException;

final class LoginConfirmationUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The login confirmation cannot be resent yet.');
    }
}
