<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\TotpCredential;
use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

final readonly class TotpManager
{
    private const WINDOW = 1;

    public function __construct(
        private Google2FA $google2fa,
        private TotpSecretCipher $cipher,
    ) {}

    public function pendingSecret(User $user): string
    {
        $credential = $user->totpCredential()->first();

        if ($credential instanceof TotpCredential && $credential->confirmed_at !== null) {
            throw new \LogicException('A confirmed TOTP credential already exists.');
        }

        if (! $credential instanceof TotpCredential) {
            $secret = $this->google2fa->generateSecretKey();
            $credential = TotpCredential::query()->create([
                'user_id' => $user->getKey(),
                'encrypted_secret' => $this->cipher->encrypt($secret),
            ]);

            return $secret;
        }

        return $this->cipher->decrypt($credential->encrypted_secret);
    }

    public function confirm(User $user, #[\SensitiveParameter] string $code): bool
    {
        return $this->verifyAndAdvance($user, $code, false);
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        return $this->verifyAndAdvance($user, $code, true);
    }

    private function verifyAndAdvance(User $user, #[\SensitiveParameter] string $code, bool $mustBeConfirmed): bool
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }

        return DB::transaction(function () use ($user, $code, $mustBeConfirmed): bool {
            DB::table('totp_credentials')
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();
            $credential = TotpCredential::query()
                ->where('user_id', $user->getKey())
                ->first();

            if (! $credential instanceof TotpCredential
                || ($mustBeConfirmed && $credential->confirmed_at === null)) {
                return false;
            }

            $secret = $this->cipher->decrypt($credential->encrypted_secret);
            $timestep = $this->google2fa->verifyKeyNewer(
                $secret,
                $code,
                $credential->last_used_timestep ?? 0,
                self::WINDOW,
            );
            unset($secret);

            if (! is_int($timestep)) {
                return false;
            }

            $credential->forceFill([
                'last_used_timestep' => $timestep,
                'confirmed_at' => $credential->confirmed_at ?? now(),
            ])->save();

            return true;
        });
    }
}
