<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\EnrollmentRevocationAudit;
use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\DB;

final class DeactivateUser
{
    public function __construct(
        private readonly LastActiveAdministratorGuard $guard,
        private readonly EnrollmentRevocationAudit $enrollmentAudit,
    ) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first();
            $lockedUser = User::query()->findOrFail($user->getKey());
            $this->guard->assertMayRemovePrivileges($lockedUser);
            $this->enrollmentAudit->recordIfPresent($lockedUser);
            $lockedUser->sessions()->delete();
            $lockedUser->update(['is_active' => false]);
        });
    }
}
