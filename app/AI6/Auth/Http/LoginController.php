<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\EmailNormalizer;
use App\AI6\Auth\EnrollmentSessionManager;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StrongFactorInventory;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class LoginController
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(
        Request $request,
        RateLimiter $rateLimiter,
        AuthConfiguration $configuration,
        EmailNormalizer $normalizer,
        AuthenticationSession $authenticationSession,
        EnrollmentSessionManager $enrollment,
        StrongFactorInventory $factors,
    ): RedirectResponse {
        $emailInput = $request->input('email');

        if (is_string($emailInput)) {
            $request->merge(['email' => $normalizer->normalize($emailInput)]);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $email = $validated['email'];
        $rateLimitKey = 'ai6-login:'.hash('sha256', $email);

        if ($rateLimiter->tooManyAttempts($rateLimitKey, $configuration->loginMaxAttempts)) {
            throw new HttpException(429, 'Zu viele Loginversuche.', null, [
                'Retry-After' => (string) $rateLimiter->availableIn($rateLimitKey),
            ]);
        }

        if (! $this->guard()->attempt([
            'email' => $email,
            'password' => $validated['password'],
            'is_active' => true,
        ])) {
            $attempts = $rateLimiter->hit($rateLimitKey, $configuration->loginDecaySeconds);

            if ($attempts >= $configuration->loginMaxAttempts) {
                throw new HttpException(429, 'Zu viele Loginversuche.', null, [
                    'Retry-After' => (string) $rateLimiter->availableIn($rateLimitKey),
                ]);
            }

            throw ValidationException::withMessages([
                'email' => 'Die Anmeldedaten sind ungültig.',
            ]);
        }

        $rateLimiter->clear($rateLimitKey);

        $user = $request->user();
        if (! $user instanceof User) {
            throw new LogicException('The authenticated principal must be an AI6 user.');
        }

        if (! $factors->hasStrongFactor($user)) {
            $enrollment->start($request, $user);

            return redirect()->route('auth.enrollment.totp.show');
        }

        $authenticationSession->beginPrimaryPending($request);

        return redirect()->route('auth.primary.factor');
    }

    public function destroy(Request $request, EnrollmentSessionManager $enrollment): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $enrollment->revoke($request, $user);
        }
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function guard(): StatefulGuard
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException('The web authentication guard must be stateful.');
        }

        return $guard;
    }
}
