<?php

namespace App\AI6\Auth;

enum LoginConfirmationDeliveryStatus: string
{
    case FAILED = 'failed';
    case QUEUED = 'queued';
    case SENT = 'sent';
}
