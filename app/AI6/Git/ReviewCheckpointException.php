<?php

namespace App\AI6\Git;

final class ReviewCheckpointException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The review checkpoint binding is invalid.');
    }
}
