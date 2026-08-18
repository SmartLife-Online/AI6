<?php

namespace App\AI6\HumanLoop;

enum HumanRequestDeliveryStatus: string
{
    case FAILED = 'failed';
    case QUEUED = 'queued';
    case SENT = 'sent';
}
