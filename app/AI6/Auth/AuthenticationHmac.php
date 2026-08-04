<?php

namespace App\AI6\Auth;

use App\AI6\Shared\Security\CanonicalByteFrame;
use Illuminate\Encryption\Encrypter;

final readonly class AuthenticationHmac
{
    public function __construct(private Encrypter $encrypter) {}

    /** @param list<int|string> $fields */
    public function digest(string $domain, array $fields): string
    {
        $payload = CanonicalByteFrame::encode(
            $domain,
            array_map(static fn (int|string $field): string => (string) $field, $fields),
        );

        return hash_hmac('sha256', $payload, $this->encrypter->getKey());
    }
}
