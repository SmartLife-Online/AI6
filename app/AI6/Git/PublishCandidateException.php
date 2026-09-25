<?php

namespace App\AI6\Git;

use RuntimeException;
use Throwable;

final class PublishCandidateException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
