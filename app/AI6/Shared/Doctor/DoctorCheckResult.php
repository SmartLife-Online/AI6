<?php

namespace App\AI6\Shared\Doctor;

final readonly class DoctorCheckResult
{
    /** @param array<string, string> $details */
    public function __construct(
        public bool $passed,
        public array $details,
    ) {}
}
