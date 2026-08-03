<?php

namespace App\AI6\Shared\Doctor;

interface DoctorCheck
{
    public function label(): string;

    public function run(): DoctorCheckResult;
}
