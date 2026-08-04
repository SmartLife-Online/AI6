<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\User;

final class StrongFactorInventory
{
    public function hasPasskey(User $user): bool
    {
        return $user->passkeyCredentials()->exists();
    }

    public function hasTotp(User $user): bool
    {
        return $user->totpCredential()->whereNotNull('confirmed_at')->exists();
    }

    public function hasStrongFactor(User $user): bool
    {
        return $this->hasPasskey($user) || $this->hasTotp($user);
    }
}
