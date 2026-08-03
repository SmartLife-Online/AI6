<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\User;

final class GrantGlobalAdministrator
{
    public function handle(User $user): void
    {
        $user->update(['is_global_admin' => true]);
    }
}
