<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\CannotRemoveLastAdministrator;
use App\AI6\Auth\Models\User;

final class LastActiveAdministratorGuard
{
    public function assertMayRemovePrivileges(User $user): void
    {
        if (! $user->is_active || ! $user->is_global_admin) {
            return;
        }

        $activeAdministratorCount = User::query()
            ->where('is_active', true)
            ->where('is_global_admin', true)
            ->lockForUpdate()
            ->count();

        if ($activeAdministratorCount <= 1) {
            throw new CannotRemoveLastAdministrator;
        }
    }
}
