<?php

namespace App\AI6\Auth;

final readonly class PasskeyOptions
{
    /** @param array<string, mixed> $publicKey */
    public function __construct(
        public array $publicKey,
        public string $challenge,
    ) {}
}
