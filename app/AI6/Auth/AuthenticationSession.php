<?php

namespace App\AI6\Auth;

use Illuminate\Http\Request;

final class AuthenticationSession
{
    public const STATE_AUTHORIZED = 'authorized';

    public const STATE_EMAIL_PENDING = 'email_pending';

    public const STATE_ENROLLMENT = 'enrollment';

    public const STATE_KEY = 'ai6.auth.state';

    public const STATE_PRIMARY_PENDING = 'primary_pending';

    public function state(Request $request): ?string
    {
        $state = $request->session()->get(self::STATE_KEY);

        return is_string($state) ? $state : null;
    }

    public function beginPrimaryPending(Request $request): void
    {
        $request->session()->put(self::STATE_KEY, self::STATE_PRIMARY_PENDING);
        $request->session()->forget(['ai6.auth.email', 'ai6.auth.enrollment', 'ai6.auth.strong_attempts']);
    }

    /** @param array<string, int|string> $enrollment */
    public function beginEnrollment(Request $request, array $enrollment): void
    {
        $request->session()->put(self::STATE_KEY, self::STATE_ENROLLMENT);
        $request->session()->put('ai6.auth.enrollment', $enrollment);
        $request->session()->forget(['ai6.auth.email', 'ai6.auth.primary', 'ai6.auth.strong_attempts']);
    }

    public function beginEmailConfirmation(Request $request, string $challengeId, int $revision): void
    {
        $request->session()->put(self::STATE_KEY, self::STATE_EMAIL_PENDING);
        $request->session()->put('ai6.auth.email', [
            'challenge_id' => $challengeId,
            'revision' => $revision,
        ]);
        $request->session()->forget(['ai6.auth.enrollment', 'ai6.auth.primary', 'ai6.auth.strong_attempts']);
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([
            self::STATE_KEY,
            'ai6.auth.email',
            'ai6.auth.enrollment',
            'ai6.auth.primary',
            'ai6.auth.webauthn',
            'ai6.auth.step_up',
            'ai6.auth.strong_attempts',
        ]);
    }
}
