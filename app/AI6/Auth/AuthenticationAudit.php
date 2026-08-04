<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\AuthenticationAuditEntry;
use App\AI6\Auth\Models\User;
use InvalidArgumentException;

final class AuthenticationAudit
{
    private const ALLOWED_CONTEXT_KEYS = [
        'action',
        'count',
        'execution',
        'method',
    ];

    /** @param array<string, int|string> $context */
    public function record(User|int|null $user, string $event, array $context = []): AuthenticationAuditEntry
    {
        if (array_diff(array_keys($context), self::ALLOWED_CONTEXT_KEYS) !== []) {
            throw new InvalidArgumentException('Authentication audit context contains a forbidden key.');
        }

        return AuthenticationAuditEntry::query()->create([
            'user_id' => $user instanceof User ? $user->getKey() : $user,
            'event' => $event,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
