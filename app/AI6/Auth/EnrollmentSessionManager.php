<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Models\User;
use Illuminate\Http\Request;

final readonly class EnrollmentSessionManager
{
    public function __construct(
        private AuthenticationSession $authenticationSession,
        private AuthenticationHmac $hmac,
        private AuthenticationAudit $audit,
        private AuthConfiguration $configuration,
        private StrongFactorInventory $factors,
    ) {}

    public function start(Request $request, User $user): void
    {
        if ($this->factors->hasStrongFactor($user)) {
            return;
        }

        $this->authenticationSession->beginEnrollment($request, [
            'user_id' => (int) $user->getKey(),
            'expires_at' => now()->addSeconds($this->configuration->enrollmentTtlSeconds)->getTimestamp(),
            'session_binding' => $this->sessionBinding($request),
            'password_binding' => $this->passwordBinding($user),
        ]);
        $this->audit->record($user, 'enrollment_started');
    }

    public function isValid(Request $request, User $user): bool
    {
        if ($this->authenticationSession->state($request) !== AuthenticationSession::STATE_ENROLLMENT) {
            return false;
        }

        $data = $request->session()->get('ai6.auth.enrollment');

        if (! is_array($data) || (int) ($data['user_id'] ?? 0) !== (int) $user->getKey()) {
            return false;
        }

        $valid = $user->is_active
            && ! $this->factors->hasStrongFactor($user)
            && (int) ($data['expires_at'] ?? 0) >= now()->getTimestamp()
            && hash_equals((string) ($data['session_binding'] ?? ''), $this->sessionBinding($request))
            && hash_equals((string) ($data['password_binding'] ?? ''), $this->passwordBinding($user));

        if (! $valid) {
            $event = (int) ($data['expires_at'] ?? 0) < now()->getTimestamp()
                ? 'enrollment_expired'
                : 'enrollment_revoked';
            $this->audit->record($user, $event);
            $this->authenticationSession->clear($request);
        }

        return $valid;
    }

    public function complete(Request $request, User $user): void
    {
        $this->audit->record($user, 'enrollment_completed');
        $this->authenticationSession->clear($request);
    }

    public function revoke(Request $request, User $user): void
    {
        if ($this->authenticationSession->state($request) === AuthenticationSession::STATE_ENROLLMENT) {
            $this->audit->record($user, 'enrollment_revoked');
        }

        $this->authenticationSession->clear($request);
    }

    private function sessionBinding(Request $request): string
    {
        return $this->hmac->digest('AI6-AUTH-ENROLLMENT-SESSION-V1', [$request->session()->getId()]);
    }

    private function passwordBinding(User $user): string
    {
        return $this->hmac->digest('AI6-AUTH-ENROLLMENT-PASSWORD-V1', [(string) $user->getAuthPassword()]);
    }
}
