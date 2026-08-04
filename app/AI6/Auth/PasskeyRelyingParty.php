<?php

namespace App\AI6\Auth;

final readonly class PasskeyRelyingParty
{
    public function __construct(
        public string $name,
        public string $id,
        public string $origin,
    ) {}
}
