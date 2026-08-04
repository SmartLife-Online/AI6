<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyManager;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Auth\StrongAuthenticationAttemptLimiter;
use App\AI6\Auth\TotpManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class StepUpController
{
    public function __construct(
        private TotpManager $totp,
        private PasskeyManager $passkeys,
        private StepUpGuard $stepUp,
        private StrongAuthenticationAttemptLimiter $attempts,
    ) {}

    public function verifyTotp(Request $request, string $action): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $this->user($request);
        $scope = $this->scope($action);
        $this->assertAttemptsAvailable($request, $user, $scope);

        if (! $this->totp->verify($user, $validated['code'])) {
            $this->rejectAttempt($request, $user, $scope, 'code', 'Der TOTP-Code ist ungültig oder wurde bereits verwendet.');
        }

        $this->attempts->clear($request, $user, $scope);
        $this->stepUp->markSatisfied($request, $user, $action);

        return back()->with('status', 'Step-up bestätigt.');
    }

    public function passkeyOptions(Request $request, string $action): JsonResponse
    {
        $user = $this->user($request);
        $scope = $this->scope($action);
        $this->assertAttemptsAvailable($request, $user, $scope);

        try {
            $options = $this->passkeys->authenticationOptions(
                $request,
                $user,
                $scope,
            );
        } catch (PasskeyCeremonyRejectedException) {
            throw ValidationException::withMessages(['passkey' => 'Die Passkey-Prüfung wurde abgelehnt.']);
        }

        return response()->json(['publicKey' => $options]);
    }

    public function verifyPasskey(Request $request, string $action): JsonResponse
    {
        $validated = $request->validate([
            'credential_id' => ['required', 'string', 'max:2048'],
            'client_data_json' => ['required', 'string', 'max:16384'],
            'authenticator_data' => ['required', 'string', 'max:16384'],
            'signature' => ['required', 'string', 'max:16384'],
            'user_handle' => ['nullable', 'string', 'max:2048'],
        ]);
        $user = $this->user($request);
        $scope = $this->scope($action);
        $this->assertAttemptsAvailable($request, $user, $scope);

        try {
            $this->passkeys->authenticate($request, $user, $validated, $scope);
        } catch (PasskeyCeremonyRejectedException) {
            $this->rejectAttempt($request, $user, $scope, 'passkey', 'Die Passkey-Prüfung wurde abgelehnt.');
        }

        $this->attempts->clear($request, $user, $scope);
        $this->stepUp->markSatisfied($request, $user, $action);

        return response()->json(['status' => 'ok']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('The authenticated principal must be an AI6 user.');
        }

        return $user;
    }

    private function scope(string $action): string
    {
        return 'step-up:'.$action;
    }

    private function assertAttemptsAvailable(Request $request, User $user, string $scope): void
    {
        if ($this->attempts->isLocked($request, $user, $scope)) {
            throw ValidationException::withMessages([
                'authentication' => 'Die zulässigen Step-up-Prüfversuche sind ausgeschöpft.',
            ]);
        }
    }

    private function rejectAttempt(
        Request $request,
        User $user,
        string $scope,
        string $field,
        string $message,
    ): never {
        $locked = $this->attempts->recordFailure($request, $user, $scope);

        throw ValidationException::withMessages([
            $field => $locked
                ? 'Die zulässigen Step-up-Prüfversuche sind ausgeschöpft.'
                : $message,
        ]);
    }
}
