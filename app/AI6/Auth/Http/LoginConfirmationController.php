<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\LoginCompletionGate;
use App\AI6\Auth\LoginConfirmationManager;
use App\AI6\Auth\LoginConfirmationUnavailableException;
use App\AI6\Auth\LoginConfirmationVerification;
use App\AI6\Auth\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class LoginConfirmationController
{
    public function __construct(
        private LoginConfirmationManager $confirmations,
        private LoginCompletionGate $completionGate,
        private AuthenticationSession $authenticationSession,
    ) {}

    public function show(Request $request): View
    {
        $confirmation = $this->confirmations->current($request, $this->user($request));

        return view('auth.login-confirmation', ['confirmation' => $confirmation]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{8}$/'],
        ]);
        $user = $this->user($request);
        $result = $this->confirmations->verify($request, $user, $validated['code']);

        if ($result === LoginConfirmationVerification::LOCKED) {
            return $this->rejectAndLogout(
                $request,
                'Die zulässigen Bestätigungsversuche sind ausgeschöpft. Bitte melden Sie sich erneut an.',
            );
        }

        if ($result !== LoginConfirmationVerification::SUCCESS) {
            throw ValidationException::withMessages([
                'code' => match ($result) {
                    LoginConfirmationVerification::EXPIRED => 'Der Bestätigungscode ist abgelaufen.',
                    default => 'Der Bestätigungscode ist ungültig oder nicht mehr verwendbar.',
                },
            ]);
        }

        if (! $this->completionGate->emailConfirmed($request, $user)) {
            return $this->rejectAndLogout(
                $request,
                'Der Anmeldezustand ist nicht mehr verwendbar. Bitte melden Sie sich erneut an.',
            );
        }

        return redirect()->intended(route('projects.index'));
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $issue = $this->confirmations->resend($request, $this->user($request));
        } catch (LoginConfirmationUnavailableException) {
            throw ValidationException::withMessages([
                'code' => 'Ein neuer Code kann noch nicht angefordert werden.',
            ]);
        }

        $this->authenticationSession->beginEmailConfirmation(
            $request,
            $issue->confirmation->id,
            $issue->confirmation->revision,
        );

        return redirect()->route('auth.confirmation.show');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('The authenticated principal must be an AI6 user.');
        }

        return $user;
    }

    private function guard(): StatefulGuard
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new LogicException('The web authentication guard must be stateful.');
        }

        return $guard;
    }

    private function rejectAndLogout(Request $request, string $message): RedirectResponse
    {
        $this->authenticationSession->clear($request);
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
