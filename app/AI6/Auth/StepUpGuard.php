<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Models\User;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Http\Request;

final readonly class StepUpGuard
{
    public function __construct(
        private SecurityPolicy $policy,
        private AuthConfiguration $configuration,
        private AuthenticationHmac $hmac,
        private AuthenticationAudit $audit,
    ) {}

    public function markSatisfied(Request $request, User $user, string $action): void
    {
        $this->assertAction($action);
        $proofs = $this->pruneExpiredProofs($request);
        $proofs[hash('sha256', $action)] = [
            'user_id' => (int) $user->getKey(),
            'action' => $action,
            'session_binding' => $this->hmac->digest('AI6-AUTH-STEP-UP-SESSION-V1', [$request->session()->getId()]),
            'expires_at' => now()->addSeconds($this->configuration->stepUpWindowSeconds)->getTimestamp(),
        ];
        $this->writeProofs($request, $proofs);
        $this->audit->record($user, 'step_up_satisfied', ['action' => $action]);
    }

    public function consumeFresh(Request $request, User $user, string $action): void
    {
        $proofs = $this->pruneExpiredProofs($request);
        $this->writeProofs($request, $proofs);

        if (! $this->policy->isEnabled(SecurityMeasure::REQUIRE_CRITICAL_ACTION_STEP_UP)) {
            return;
        }

        $this->assertAction($action);
        $key = hash('sha256', $action);
        $proof = $proofs[$key] ?? null;
        $binding = $this->hmac->digest('AI6-AUTH-STEP-UP-SESSION-V1', [$request->session()->getId()]);

        if (! is_array($proof)
            || ! is_int($proof['user_id'] ?? null)
            || $proof['user_id'] !== (int) $user->getKey()
            || ! is_string($proof['action'] ?? null)
            || $proof['action'] !== $action
            || ! is_int($proof['expires_at'] ?? null)
            || $proof['expires_at'] <= now()->getTimestamp()
            || ! is_string($proof['session_binding'] ?? null)
            || ! hash_equals($proof['session_binding'], $binding)) {
            throw new StepUpRequiredException;
        }

        unset($proofs[$key]);
        $this->writeProofs($request, $proofs);
    }

    /** @return array<string, mixed> */
    private function pruneExpiredProofs(Request $request): array
    {
        $stored = $request->session()->get('ai6.auth.step_up', []);
        $proofs = is_array($stored) ? $stored : [];
        $now = now()->getTimestamp();

        foreach ($proofs as $key => $proof) {
            if (! is_array($proof)
                || ! is_int($proof['expires_at'] ?? null)
                || $proof['expires_at'] <= $now) {
                unset($proofs[$key]);
            }
        }

        return $proofs;
    }

    /** @param array<string, mixed> $proofs */
    private function writeProofs(Request $request, array $proofs): void
    {
        if ($proofs === []) {
            $request->session()->forget('ai6.auth.step_up');

            return;
        }

        $request->session()->put('ai6.auth.step_up', $proofs);
    }

    private function assertAction(string $action): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $action) !== 1) {
            throw new \InvalidArgumentException('Invalid step-up action type.');
        }
    }
}
