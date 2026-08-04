<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Jobs\SendLoginConfirmationMail;
use App\AI6\Auth\Models\LoginConfirmation;
use App\AI6\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final readonly class LoginConfirmationManager
{
    public const CODE_LENGTH = 8;

    public function __construct(
        private AuthConfiguration $configuration,
        private AuthenticationHmac $hmac,
        private AuthenticationAudit $audit,
    ) {}

    public function issue(Request $request, User $user): LoginConfirmationIssue
    {
        return $this->createRevision($request, $user, false);
    }

    public function resend(Request $request, User $user): LoginConfirmationIssue
    {
        return $this->createRevision($request, $user, true);
    }

    public function verify(
        Request $request,
        User $user,
        #[\SensitiveParameter] string $code,
    ): LoginConfirmationVerification {
        $sessionData = $request->session()->get('ai6.auth.email');

        if (! is_array($sessionData)) {
            return LoginConfirmationVerification::UNAVAILABLE;
        }

        return DB::transaction(function () use ($request, $user, $code, $sessionData): LoginConfirmationVerification {
            $challengeId = (string) ($sessionData['challenge_id'] ?? '');
            DB::table('login_confirmations')->where('id', $challengeId)->lockForUpdate()->first();
            $confirmation = LoginConfirmation::query()->find($challengeId);

            if (! $confirmation instanceof LoginConfirmation
                || $confirmation->revision !== (int) ($sessionData['revision'] ?? 0)
                || $confirmation->user_id !== (int) $user->getKey()
                || $confirmation->consumed_at !== null
                || $confirmation->invalidated_at !== null
                || $confirmation->delivery_status !== LoginConfirmationDeliveryStatus::SENT->value
                || ! hash_equals($confirmation->session_digest, $this->sessionDigest($request))) {
                return LoginConfirmationVerification::UNAVAILABLE;
            }

            $recipient = $this->validRecipient();
            if ($recipient === null
                || ! hash_equals($confirmation->recipient_digest, $this->recipientDigest($recipient))) {
                $confirmation->forceFill(['invalidated_at' => now()])->save();

                return LoginConfirmationVerification::UNAVAILABLE;
            }

            if ($confirmation->expires_at->isPast()) {
                $confirmation->forceFill(['invalidated_at' => now()])->save();

                return LoginConfirmationVerification::EXPIRED;
            }

            if ($confirmation->attempt_count >= $this->configuration->loginConfirmationMaxAttempts) {
                $confirmation->forceFill(['invalidated_at' => now()])->save();
                $this->audit->record($user, 'login_confirmation_locked', ['method' => 'email_confirmation']);

                return LoginConfirmationVerification::LOCKED;
            }

            $confirmation->attempt_count++;
            $digest = $this->codeDigest(
                $confirmation->id,
                $confirmation->revision,
                $confirmation->session_digest,
                $confirmation->recipient_digest,
                $code,
            );

            if (preg_match('/^\d{'.self::CODE_LENGTH.'}$/D', $code) !== 1
                || ! hash_equals($confirmation->code_digest, $digest)) {
                $locked = $confirmation->attempt_count >= $this->configuration->loginConfirmationMaxAttempts;

                if ($locked) {
                    $confirmation->forceFill(['invalidated_at' => now()]);
                }
                $confirmation->save();

                if ($locked) {
                    $this->audit->record($user, 'login_confirmation_locked', ['method' => 'email_confirmation']);
                }

                return $locked
                    ? LoginConfirmationVerification::LOCKED
                    : LoginConfirmationVerification::INVALID;
            }

            $confirmation->forceFill(['consumed_at' => now()])->save();

            return LoginConfirmationVerification::SUCCESS;
        });
    }

    public function current(Request $request, User $user): ?LoginConfirmation
    {
        $data = $request->session()->get('ai6.auth.email');

        if (! is_array($data)) {
            return null;
        }

        return LoginConfirmation::query()
            ->whereKey((string) ($data['challenge_id'] ?? ''))
            ->where('revision', (int) ($data['revision'] ?? 0))
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function invalidateRecipientMismatches(User $user): void
    {
        $recipient = $this->validRecipient();
        $query = LoginConfirmation::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at');

        if ($recipient !== null) {
            $query->where('recipient_digest', '!=', $this->recipientDigest($recipient));
        }

        $query->update(['invalidated_at' => now(), 'updated_at' => now()]);
    }

    private function createRevision(Request $request, User $user, bool $isResend): LoginConfirmationIssue
    {
        return DB::transaction(function () use ($request, $user, $isResend): LoginConfirmationIssue {
            $sessionDigest = $this->sessionDigest($request);
            $recipient = $this->validRecipient();
            $recipientForBinding = $recipient ?? 'unavailable';
            $recipientDigest = $this->recipientDigest($recipientForBinding);
            DB::table('login_confirmations')
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->get();
            $current = LoginConfirmation::query()
                ->where('user_id', $user->getKey())
                ->where('session_digest', $sessionDigest)
                ->get()
                ->sortByDesc(static fn (LoginConfirmation $confirmation): int => $confirmation->revision)
                ->first();

            if ($isResend && $current instanceof LoginConfirmation
                && $current->created_at->addSeconds($this->configuration->loginConfirmationResendCooldownSeconds)->isFuture()) {
                throw new LoginConfirmationUnavailableException;
            }

            $recipientMismatches = LoginConfirmation::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at');

            if ($recipient !== null) {
                $recipientMismatches->where('recipient_digest', '!=', $recipientDigest);
            }

            $recipientMismatches->update(['invalidated_at' => now(), 'updated_at' => now()]);

            LoginConfirmation::query()
                ->where('user_id', $user->getKey())
                ->where('session_digest', $sessionDigest)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'updated_at' => now()]);

            $revision = $current instanceof LoginConfirmation ? $current->revision + 1 : 1;
            $id = (string) Str::uuid();
            $code = str_pad(
                (string) random_int(0, (10 ** self::CODE_LENGTH) - 1),
                self::CODE_LENGTH,
                '0',
                STR_PAD_LEFT,
            );
            $status = $recipient === null
                ? LoginConfirmationDeliveryStatus::FAILED
                : LoginConfirmationDeliveryStatus::QUEUED;

            $confirmation = LoginConfirmation::query()->create([
                'id' => $id,
                'user_id' => $user->getKey(),
                'revision' => $revision,
                'code_digest' => $this->codeDigest(
                    $id,
                    $revision,
                    $sessionDigest,
                    $recipientDigest,
                    $code,
                ),
                'recipient_digest' => $recipientDigest,
                'session_digest' => $sessionDigest,
                'expires_at' => now()->addSeconds($this->configuration->loginConfirmationTtlSeconds),
                'attempt_count' => 0,
                'delivery_status' => $status->value,
                'delivery_status_changed_at' => now(),
                'failure_key' => $recipient === null ? 'recipient_unavailable' : null,
            ]);

            if ($recipient !== null) {
                DB::afterCommit(function () use ($confirmation, $recipient, $code): void {
                    try {
                        SendLoginConfirmationMail::dispatch(
                            $confirmation->id,
                            $confirmation->revision,
                            $recipient,
                            $code,
                        );
                    } catch (Throwable) {
                        LoginConfirmation::query()
                            ->whereKey($confirmation->id)
                            ->where('revision', $confirmation->revision)
                            ->where('delivery_status', LoginConfirmationDeliveryStatus::QUEUED->value)
                            ->update([
                                'delivery_status' => LoginConfirmationDeliveryStatus::FAILED->value,
                                'delivery_status_changed_at' => now(),
                                'failure_key' => 'queue_dispatch_failed',
                                'updated_at' => now(),
                            ]);
                        Log::warning('Login confirmation queue dispatch failed.', [
                            'challenge_id' => $confirmation->id,
                            'revision' => $confirmation->revision,
                        ]);
                    }
                });
            }

            unset($code);

            return new LoginConfirmationIssue($confirmation, $status);
        });
    }

    private function validRecipient(): ?string
    {
        $email = $this->configuration->loginConfirmationEmail;

        if ($email === null
            || Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            return null;
        }

        return $email;
    }

    private function sessionDigest(Request $request): string
    {
        return $this->hmac->digest('AI6-LOGIN-CONFIRMATION-SESSION-V1', [$request->session()->getId()]);
    }

    private function recipientDigest(string $recipient): string
    {
        return $this->hmac->digest('AI6-LOGIN-CONFIRMATION-RECIPIENT-V1', [strtolower($recipient)]);
    }

    private function codeDigest(
        string $id,
        int $revision,
        string $sessionDigest,
        string $recipientDigest,
        #[\SensitiveParameter] string $code,
    ): string {
        return $this->hmac->digest('AI6-LOGIN-CONFIRMATION-CODE-V1', [
            $id,
            $revision,
            $sessionDigest,
            $recipientDigest,
            $code,
        ]);
    }
}
