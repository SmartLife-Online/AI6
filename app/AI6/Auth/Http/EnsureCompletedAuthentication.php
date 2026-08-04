<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\EnrollmentSessionManager;
use App\AI6\Auth\LoginConfirmationManager;
use App\AI6\Auth\Models\User;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCompletedAuthentication
{
    public function __construct(
        private AuthenticationSession $authenticationSession,
        private EnrollmentSessionManager $enrollment,
        private LoginConfirmationManager $confirmations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $state = $this->authenticationSession->state($request);
        $routeName = $request->route()->getName();
        $routeName = is_string($routeName) ? $routeName : '';

        if ($routeName === 'logout') {
            return $next($request);
        }

        if ($state === AuthenticationSession::STATE_AUTHORIZED) {
            if ($this->isPreAuthorizationRoute($routeName)) {
                return $this->authorizedTarget($request);
            }

            return $next($request);
        }

        if ($state === AuthenticationSession::STATE_ENROLLMENT) {
            if (! $this->enrollment->isValid($request, $user)) {
                return $this->rejectAndLogout($request);
            }

            if (str_starts_with($routeName, 'auth.enrollment.')) {
                return $next($request);
            }

            return $this->reject($request, route('auth.enrollment.totp.show'));
        }

        if ($state === AuthenticationSession::STATE_PRIMARY_PENDING) {
            if (str_starts_with($routeName, 'auth.primary.')) {
                return $next($request);
            }

            return $this->reject($request, route('auth.primary.factor'));
        }

        if ($state === AuthenticationSession::STATE_EMAIL_PENDING) {
            $this->confirmations->invalidateRecipientMismatches($user);

            if (str_starts_with($routeName, 'auth.confirmation.')) {
                return $next($request);
            }

            return $this->reject($request, route('auth.confirmation.show'));
        }

        return $this->rejectAndLogout($request);
    }

    private function isPreAuthorizationRoute(string $routeName): bool
    {
        return str_starts_with($routeName, 'auth.primary.')
            || str_starts_with($routeName, 'auth.confirmation.')
            || str_starts_with($routeName, 'auth.enrollment.');
    }

    private function authorizedTarget(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Authentifizierung bereits abgeschlossen.'], 409);
        }

        return redirect()->intended(route('projects.index'));
    }

    private function reject(Request $request, string $redirect): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Authentifizierung nicht abgeschlossen.'], 403);
        }

        return new RedirectResponse($redirect);
    }

    private function rejectAndLogout(Request $request): Response
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException('The web authentication guard must be stateful.');
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Nicht authentifiziert.'], 401);
        }

        return new RedirectResponse(route('login'));
    }
}
