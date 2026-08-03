<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\User;

final class CreateUser
{
    public function handle(string $name, string $email, string $password): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'is_global_admin' => false,
        ]);
    }
}
