<?php

namespace App\AI6\Auth;

use Illuminate\Support\Str;

final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
