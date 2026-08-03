<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\EmailNormalizer;
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

        return redirect()->intended(route('projects.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
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
