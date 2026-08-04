<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\EnrollmentSessionManager;
use App\AI6\Auth\Models\User;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureActiveUser
{
    public function __construct(private EnrollmentSessionManager $enrollment) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            $this->enrollment->revoke($request, $user);
            $this->guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Nicht authentifiziert.'], 401);
            }

            return redirect()->route('login');
        }

        return $next($request);
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
