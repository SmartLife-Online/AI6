<?php

namespace App\AI6\Auth;

use RuntimeException;

final class PasskeyCeremonyRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The WebAuthn ceremony was rejected.');
    }
}
