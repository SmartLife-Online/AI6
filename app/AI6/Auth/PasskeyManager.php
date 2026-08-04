<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class PasskeyManager
{
    public function __construct(
        private PasskeyCeremony $ceremony,
        private PasskeyChallengeManager $challenges,
        private AuthenticationAudit $audit,
    ) {}

    /** @return array<string, mixed> */
    public function registrationOptions(Request $request, User $user): array
    {
        $ids = $user->passkeyCredentials()->pluck('credential_id')->all();
        $options = $this->ceremony->registrationOptions($user, $ids);
        $this->challenges->store($request, $user, 'registration', $options->challenge);

        return $options->publicKey;
    }

    /** @param array<string, mixed> $response */
    public function register(Request $request, User $user, array $response, ?string $label = null): PasskeyCredential
    {
        $challenge = $this->challenges->consume($request, $user, 'registration');
        $registration = $this->ceremony->verifyRegistration($response, $challenge);

        try {
            $credential = PasskeyCredential::query()->create([
                'user_id' => $user->getKey(),
                'credential_id' => $registration->credentialId,
                'credential_public_key' => $registration->publicKey,
                'signature_counter' => $registration->signatureCounter,
                'label' => $label,
            ]);
        } catch (QueryException) {
            throw new PasskeyCeremonyRejectedException;
        }

        $this->audit->record($user, 'passkey_registered');

        return $credential;
    }

    /** @return array<string, mixed> */
    public function authenticationOptions(Request $request, User $user, string $purpose = 'login'): array
    {
        $ids = $user->passkeyCredentials()->pluck('credential_id')->all();

        if ($ids === []) {
            throw new PasskeyCeremonyRejectedException;
        }

        $options = $this->ceremony->authenticationOptions($ids);
        $this->challenges->store($request, $user, $purpose, $options->challenge);

        return $options->publicKey;
    }

    /** @param array<string, mixed> $response */
    public function authenticate(
        Request $request,
        User $user,
        array $response,
        string $purpose = 'login',
    ): void {
        $challenge = $this->challenges->consume($request, $user, $purpose);
        $credentialId = $response['credential_id'] ?? null;

        if (! is_string($credentialId)) {
            throw new PasskeyCeremonyRejectedException;
        }

        DB::transaction(function () use ($user, $response, $challenge, $credentialId): void {
            DB::table('passkey_credentials')
                ->where('user_id', $user->getKey())
                ->where('credential_id', $credentialId)
                ->lockForUpdate()
                ->first();
            $credential = PasskeyCredential::query()
                ->where('user_id', $user->getKey())
                ->where('credential_id', $credentialId)
                ->first();

            if (! $credential instanceof PasskeyCredential) {
                throw new PasskeyCeremonyRejectedException;
            }

            $counter = $this->ceremony->verifyAuthentication($credential, $user, $response, $challenge);
            $credential->forceFill(['signature_counter' => $counter])->save();
        });
    }
}
