<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\User;

interface PasskeyCeremony
{
    public const TIMEOUT_SECONDS = 300;

    /** @param list<string> $excludedCredentialIds */
    public function registrationOptions(User $user, array $excludedCredentialIds): PasskeyOptions;

    /** @param list<string> $allowedCredentialIds */
    public function authenticationOptions(array $allowedCredentialIds): PasskeyOptions;

    /** @param array<string, mixed> $response */
    public function verifyRegistration(array $response, string $challenge): PasskeyRegistration;

    /** @param array<string, mixed> $response */
    public function verifyAuthentication(
        PasskeyCredential $credential,
        User $user,
        array $response,
        string $challenge,
    ): int;
}
