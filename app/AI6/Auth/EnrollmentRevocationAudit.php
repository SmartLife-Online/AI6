<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\User;
use Throwable;

final readonly class EnrollmentRevocationAudit
{
    public function __construct(private AuthenticationAudit $audit) {}

    public function recordIfPresent(User $user): void
    {
        foreach ($user->sessions()->pluck('payload') as $payload) {
            if (is_string($payload) && $this->containsEnrollmentState($payload)) {
                $this->audit->record($user, 'enrollment_revoked');

                return;
            }
        }
    }

    private function containsEnrollmentState(string $payload): bool
    {
        $decoded = base64_decode($payload, true);

        if (! is_string($decoded)) {
            return false;
        }

        try {
            $data = unserialize($decoded, ['allowed_classes' => false]);
        } catch (Throwable) {
            return false;
        }

        return is_array($data)
            && ($data[AuthenticationSession::STATE_KEY] ?? null) === AuthenticationSession::STATE_ENROLLMENT;
    }
}
