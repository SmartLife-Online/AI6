<?php

namespace App\AI6\Shared\Process;

enum MailboxMessageType: string
{
    case REQUEST = 'request';
    case RESULT = 'result';
}
