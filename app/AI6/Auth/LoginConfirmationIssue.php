<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\LoginConfirmation;

final readonly class LoginConfirmationIssue
{
    public function __construct(
        public LoginConfirmation $confirmation,
        public LoginConfirmationDeliveryStatus $deliveryStatus,
    ) {}
}
