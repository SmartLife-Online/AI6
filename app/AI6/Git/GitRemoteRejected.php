<?php

namespace App\AI6\Git;

use RuntimeException;

final class GitRemoteRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Git remote rejected: '.$reason.'.');
    }
}
