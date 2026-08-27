<?php

namespace App\AI6\Git;

final class ReviewSubjectException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The review subject is invalid.');
    }
}
