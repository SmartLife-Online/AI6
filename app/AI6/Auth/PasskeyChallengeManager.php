<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\User;
use Illuminate\Http\Request;

final readonly class PasskeyChallengeManager
{
    public function __construct(
        private AuthenticationHmac $hmac,
    ) {}

    public function store(Request $request, User $user, string $purpose, string $challenge): void
    {
        $request->session()->put('ai6.auth.webauthn', [
            'user_id' => (int) $user->getKey(),
            'purpose' => $purpose,
            'challenge' => $challenge,
            'expires_at' => now()->addSeconds(PasskeyCeremony::TIMEOUT_SECONDS)->getTimestamp(),
            'session_binding' => $this->sessionBinding($request),
        ]);
    }

    public function consume(Request $request, User $user, string $purpose): string
    {
        $data = $request->session()->pull('ai6.auth.webauthn');

        if (! is_array($data)
            || (int) ($data['user_id'] ?? 0) !== (int) $user->getKey()
            || (string) ($data['purpose'] ?? '') !== $purpose
            || (int) ($data['expires_at'] ?? 0) < now()->getTimestamp()
            || ! hash_equals((string) ($data['session_binding'] ?? ''), $this->sessionBinding($request))
            || ! is_string($data['challenge'] ?? null)) {
            throw new PasskeyCeremonyRejectedException;
        }

        return $data['challenge'];
    }

    private function sessionBinding(Request $request): string
    {
        return $this->hmac->digest('AI6-WEBAUTHN-CHALLENGE-SESSION-V1', [$request->session()->getId()]);
    }
}
