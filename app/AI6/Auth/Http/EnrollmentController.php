<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\EnrollmentSessionManager;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyManager;
use App\AI6\Auth\TotpManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final readonly class EnrollmentController
{
    public function __construct(
        private EnrollmentSessionManager $enrollment,
        private TotpManager $totp,
        private PasskeyManager $passkeys,
    ) {}

    public function showTotp(Request $request): View
    {
        return view('auth.enroll-totp', ['secret' => $this->totp->pendingSecret($this->user($request))]);
    }

    public function confirmTotp(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $this->user($request);

        if (! $this->totp->confirm($user, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'Der TOTP-Code ist ungültig.']);
        }

        return $this->finish($request, $user);
    }

    public function showPasskey(): View
    {
        return view('auth.enroll-passkey');
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        try {
            $options = $this->passkeys->registrationOptions($request, $this->user($request));
        } catch (PasskeyCeremonyRejectedException) {
            throw ValidationException::withMessages(['passkey' => 'Die Passkey-Registrierung wurde abgelehnt.']);
        }

        return response()->json(['publicKey' => $options]);
    }

    public function registerPasskey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential_id' => ['required', 'string', 'max:2048'],
            'client_data_json' => ['required', 'string', 'max:16384'],
            'attestation_object' => ['required', 'string', 'max:65536'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $this->user($request);

        try {
            $this->passkeys->register($request, $user, $validated, $validated['label'] ?? null);
        } catch (PasskeyCeremonyRejectedException) {
            throw ValidationException::withMessages(['passkey' => 'Die Passkey-Registrierung wurde abgelehnt.']);
        }

        return response()->json(['redirect' => $this->finish($request, $user)->getTargetUrl()]);
    }

    private function finish(Request $request, User $user): RedirectResponse
    {
        $this->enrollment->complete($request, $user);
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Das starke Anmeldeverfahren wurde eingerichtet. Bitte melden Sie sich erneut an.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->enrollment->isValid($request, $user)) {
            abort(403);
        }

        return $user;
    }

    private function guard(): StatefulGuard
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new \LogicException('The web authentication guard must be stateful.');
        }

        return $guard;
    }
}
