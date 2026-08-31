<?php

namespace App\AI6\Git;

use RuntimeException;

final class PublishCandidateException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
