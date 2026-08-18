<?php

namespace App\AI6\Runs;

final readonly class ImportLimitResult
{
    public function __construct(
        public ImportLimit $limit,
        public int $observed,
        public int $maximum,
    ) {}
}
