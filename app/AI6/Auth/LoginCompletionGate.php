<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\Policies\PrimaryAuthenticationPolicy;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Http\Request;

final readonly class LoginCompletionGate
{
    public function __construct(
        private SecurityPolicy $policy,
        private AuthenticationSession $authenticationSession,
        private LoginConfirmationManager $confirmations,
        private AuthenticationAudit $audit,
        private PrimaryAuthenticationPolicy $primaryAuthenticationPolicy,
    ) {}

    public function primaryAuthenticated(
        Request $request,
        User $user,
        PrimaryAuthenticationMethod $method,
    ): LoginCompletionOutcome {
        $this->assertMethodAllowed($user, $method);
        $request->session()->put('ai6.auth.primary_method', $method->value);

        if ($this->policy->isEnabled(SecurityMeasure::LOGIN_EMAIL_CONFIRMATION)) {
            $issue = $this->confirmations->issue($request, $user);
            $this->authenticationSession->beginEmailConfirmation(
                $request,
                $issue->confirmation->id,
                $issue->confirmation->revision,
            );

            return $issue->deliveryStatus === LoginConfirmationDeliveryStatus::FAILED
                ? LoginCompletionOutcome::EMAIL_FAILED
                : LoginCompletionOutcome::EMAIL_PENDING;
        }

        $this->authorize($request, $user, $method);

        return LoginCompletionOutcome::AUTHORIZED;
    }

    public function emailConfirmed(Request $request, User $user): bool
    {
        $value = $request->session()->get('ai6.auth.primary_method');
        $method = is_string($value) ? PrimaryAuthenticationMethod::tryFrom($value) : null;

        if (! $method instanceof PrimaryAuthenticationMethod
            || ! $this->primaryAuthenticationPolicy->allows($user, $method)) {
            return false;
        }

        $this->authorize($request, $user, $method);

        return true;
    }

    private function authorize(Request $request, User $user, PrimaryAuthenticationMethod $method): void
    {
        $request->session()->forget([
            'ai6.auth.email',
            'ai6.auth.enrollment',
            'ai6.auth.primary',
            'ai6.auth.primary_method',
            'ai6.auth.webauthn',
            'ai6.auth.strong_attempts',
        ]);
        $request->session()->put(AuthenticationSession::STATE_KEY, AuthenticationSession::STATE_AUTHORIZED);
        $request->session()->put('ai6.auth.authorized_at', now()->getTimestamp());
        $this->audit->record($user, 'login_authorized', ['method' => $method->value]);
    }

    private function assertMethodAllowed(User $user, PrimaryAuthenticationMethod $method): void
    {
        if (! $this->primaryAuthenticationPolicy->allows($user, $method)) {
            throw new \LogicException('The primary authentication method is not allowed by the security policy.');
        }
    }
}
