<?php

namespace App\AI6\Runs;

final readonly class ReviewBlocker
{
    public function __construct(public string $code, public string $message) {}
}
