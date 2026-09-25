<?php

namespace App\AI6\Git;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class ProjectGitOidRule implements ValidationRule
{
    public function __construct(private ?GitObjectFormat $format) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->format?->validOid($value) !== true) {
            $fail('Das Feld :attribute muss eine vollständige Git-Objekt-ID im gebundenen Projektformat enthalten.');
        }
    }
}
