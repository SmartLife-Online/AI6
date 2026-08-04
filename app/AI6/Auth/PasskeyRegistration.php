<?php

namespace App\AI6\Auth;

final readonly class PasskeyRegistration
{
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $signatureCounter,
    ) {}
}
