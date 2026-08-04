<?php

namespace App\AI6\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

final readonly class TotpSecretCipher
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(#[\SensitiveParameter] string $secret): string
    {
        return $this->encrypter->encrypt($secret, false);
    }

    public function decrypt(#[\SensitiveParameter] string $ciphertext): string
    {
        try {
            $secret = $this->encrypter->decrypt($ciphertext, false);
        } catch (DecryptException) {
            throw new TotpSecretAuthenticationException;
        }

        if (! is_string($secret)) {
            throw new TotpSecretAuthenticationException;
        }

        return $secret;
    }
}
