<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteUser
{
    public function __construct(private readonly LastActiveAdministratorGuard $guard) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first();
            $lockedUser = User::query()->findOrFail($user->getKey());
            $this->guard->assertMayRemovePrivileges($lockedUser);
            $lockedUser->sessions()->delete();
            $lockedUser->delete();
        });
    }
}
