<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\LoginCompletionGate;
use App\AI6\Auth\LoginCompletionOutcome;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyManager;
use App\AI6\Auth\Policies\PrimaryAuthenticationPolicy;
use App\AI6\Auth\PrimaryAuthenticationMethod;
use App\AI6\Auth\RecoveryCodeManager;
use App\AI6\Auth\StrongAuthenticationAttemptLimiter;
use App\AI6\Auth\StrongFactorInventory;
use App\AI6\Auth\TotpManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class PrimaryAuthenticationController
{
    public function __construct(
        private StrongFactorInventory $factors,
        private TotpManager $totp,
        private RecoveryCodeManager $recoveryCodes,
        private PasskeyManager $passkeys,
        private PrimaryAuthenticationPolicy $primaryAuthenticationPolicy,
        private LoginCompletionGate $completionGate,
        private StrongAuthenticationAttemptLimiter $attempts,
    ) {}

    public function factor(Request $request): View
    {
        $user = $this->user($request);

        return view('auth.primary-factor', [
            'hasPasskey' => $this->factors->hasPasskey($user),
            'hasTotp' => $this->factors->hasTotp($user),
            'hasRecoveryCodes' => $this->primaryAuthenticationPolicy->allows(
                $user,
                PrimaryAuthenticationMethod::RECOVERY,
            ) && $user->recoveryCodes()->whereNull('consumed_at')->exists(),
        ]);
    }

    public function verifyTotp(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $this->user($request);
        $this->assertAttemptsAvailable($request, $user);

        if (! $this->totp->verify($user, $validated['code'])) {
            $this->rejectAttempt($request, $user, 'code', 'Der TOTP-Code ist ungültig oder wurde bereits verwendet.');
        }

        $this->attempts->clear($request, $user, StrongAuthenticationAttemptLimiter::PRIMARY_SCOPE);

        return $this->complete($request, $user, PrimaryAuthenticationMethod::TOTP);
    }

    public function verifyRecovery(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = $this->user($request);

        if (! $this->primaryAuthenticationPolicy->allows($user, PrimaryAuthenticationMethod::RECOVERY)) {
            throw ValidationException::withMessages([
                'code' => 'Recovery-Codes sind für diese privilegierte Rolle nicht als Primärverfahren zugelassen.',
            ]);
        }

        $this->assertAttemptsAvailable($request, $user);

        if (! $this->recoveryCodes->consume($user, $validated['code'])) {
            $this->rejectAttempt($request, $user, 'code', 'Der Recovery-Code ist ungültig oder bereits verbraucht.');
        }

        $this->attempts->clear($request, $user, StrongAuthenticationAttemptLimiter::PRIMARY_SCOPE);

        return $this->complete($request, $user, PrimaryAuthenticationMethod::RECOVERY);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->assertAttemptsAvailable($request, $user);

        try {
            $options = $this->passkeys->authenticationOptions($request, $user);
        } catch (PasskeyCeremonyRejectedException) {
            throw ValidationException::withMessages(['passkey' => 'Die Passkey-Prüfung wurde abgelehnt.']);
        }

        return response()->json(['publicKey' => $options]);
    }

    public function verifyPasskey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential_id' => ['required', 'string', 'max:2048'],
            'client_data_json' => ['required', 'string', 'max:16384'],
            'authenticator_data' => ['required', 'string', 'max:16384'],
            'signature' => ['required', 'string', 'max:16384'],
            'user_handle' => ['nullable', 'string', 'max:2048'],
        ]);
        $user = $this->user($request);
        $this->assertAttemptsAvailable($request, $user);

        try {
            $this->passkeys->authenticate($request, $user, $validated);
        } catch (PasskeyCeremonyRejectedException) {
            $this->rejectAttempt($request, $user, 'passkey', 'Die Passkey-Prüfung wurde abgelehnt.');
        }

        $this->attempts->clear($request, $user, StrongAuthenticationAttemptLimiter::PRIMARY_SCOPE);

        $redirect = $this->complete($request, $user, PrimaryAuthenticationMethod::PASSKEY);

        return response()->json(['redirect' => $redirect->getTargetUrl()]);
    }

    private function complete(
        Request $request,
        User $user,
        PrimaryAuthenticationMethod $method,
    ): RedirectResponse {
        $outcome = $this->completionGate->primaryAuthenticated($request, $user, $method);

        return $outcome === LoginCompletionOutcome::AUTHORIZED
            ? redirect()->intended(route('projects.index'))
            : redirect()->route('auth.confirmation.show');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('The authenticated principal must be an AI6 user.');
        }

        return $user;
    }

    private function assertAttemptsAvailable(Request $request, User $user): void
    {
        if ($this->attempts->isLocked($request, $user, StrongAuthenticationAttemptLimiter::PRIMARY_SCOPE)) {
            throw ValidationException::withMessages([
                'authentication' => 'Die zulässigen Prüfversuche sind ausgeschöpft. Bitte melden Sie sich erneut an.',
            ]);
        }
    }

    private function rejectAttempt(
        Request $request,
        User $user,
        string $field,
        string $message,
    ): never {
        $locked = $this->attempts->recordFailure(
            $request,
            $user,
            StrongAuthenticationAttemptLimiter::PRIMARY_SCOPE,
        );

        throw ValidationException::withMessages([
            $field => $locked
                ? 'Die zulässigen Prüfversuche sind ausgeschöpft. Bitte melden Sie sich erneut an.'
                : $message,
        ]);
    }
}
