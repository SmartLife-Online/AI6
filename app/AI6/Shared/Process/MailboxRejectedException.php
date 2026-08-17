<?php

namespace App\AI6\Shared\Process;

use RuntimeException;

final class MailboxRejectedException extends RuntimeException
{
    public function __construct(public readonly MailboxRejection $reason)
    {
        parent::__construct('The execution envelope was rejected: '.$reason->value.'.');
    }
}
