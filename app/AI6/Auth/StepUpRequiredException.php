<?php

namespace App\AI6\Auth;

use RuntimeException;

final class StepUpRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Fresh step-up authentication is required.');
    }
}
