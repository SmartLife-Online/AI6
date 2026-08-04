<?php

namespace App\AI6\Auth\Policies;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\PrimaryAuthenticationMethod;
use App\AI6\Projects\ProjectRole;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;

final readonly class PrimaryAuthenticationPolicy
{
    public function __construct(private SecurityPolicy $securityPolicy) {}

    public function allows(User $user, PrimaryAuthenticationMethod $method): bool
    {
        if (! $this->securityPolicy->isEnabled(SecurityMeasure::REQUIRE_PRIVILEGED_PASSKEY)
            || ! $this->isPrivileged($user)) {
            return true;
        }

        return in_array($method, [
            PrimaryAuthenticationMethod::PASSKEY,
            PrimaryAuthenticationMethod::TOTP,
        ], true);
    }

    public function isPrivileged(User $user): bool
    {
        if ($user->is_global_admin) {
            return true;
        }

        return $user->memberships()
            ->whereIn('role', [
                ProjectRole::ADMIN->value,
                ProjectRole::OPERATOR->value,
                ProjectRole::APPROVER->value,
            ])
            ->exists();
    }
}
