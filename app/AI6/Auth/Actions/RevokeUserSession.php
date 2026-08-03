<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\UserSession;

final class RevokeUserSession
{
    public function handle(UserSession $session): void
    {
        $session->delete();
    }
}
