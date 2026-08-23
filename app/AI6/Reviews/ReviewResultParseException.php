<?php

namespace App\AI6\Reviews;

final class ReviewResultParseException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The validated review result contains an unknown control value.');
    }
}
