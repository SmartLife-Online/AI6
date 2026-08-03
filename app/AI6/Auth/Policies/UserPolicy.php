<?php

namespace App\AI6\Auth\Policies;

use App\AI6\Auth\Models\User;

final class UserPolicy
{
    public function create(User $actor): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function grantGlobalAdministrator(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function revokeGlobalAdministrator(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function setMembership(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function removeMembership(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    public function revokeSession(User $actor, User $target): bool
    {
        return $this->isGlobalAdministrator($actor);
    }

    private function isGlobalAdministrator(User $actor): bool
    {
        return $actor->is_active && $actor->is_global_admin;
    }
}
