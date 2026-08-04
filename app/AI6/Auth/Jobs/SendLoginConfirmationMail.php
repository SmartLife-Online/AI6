<?php

namespace App\AI6\Auth\Jobs;

use App\AI6\Auth\LoginConfirmationDeliveryStatus;
use App\AI6\Auth\Mail\LoginConfirmationMail;
use App\AI6\Auth\Models\LoginConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendLoginConfirmationMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $challengeId,
        private readonly int $revision,
        private readonly string $recipient,
        #[\SensitiveParameter]
        private readonly string $code,
    ) {}

    public function handle(): void
    {
        $confirmation = LoginConfirmation::query()->find($this->challengeId);

        if (! $confirmation instanceof LoginConfirmation) {
            return;
        }

        if ($confirmation->revision !== $this->revision
            || $confirmation->delivery_status !== LoginConfirmationDeliveryStatus::QUEUED->value
            || $confirmation->invalidated_at !== null
            || $confirmation->consumed_at !== null) {
            return;
        }

        if (config('mail.default') !== 'smtp') {
            $confirmation->forceFill([
                'delivery_status' => LoginConfirmationDeliveryStatus::FAILED->value,
                'delivery_status_changed_at' => now(),
                'failure_key' => 'mail_transport_not_deliverable',
            ])->save();
            Log::warning('Login confirmation delivery rejected non-delivering mail transport.', [
                'challenge_id' => $this->challengeId,
                'revision' => $this->revision,
            ]);

            return;
        }

        try {
            Mail::to($this->recipient)->send(new LoginConfirmationMail(
                $this->code,
                $this->revision,
            ));
            $confirmation->forceFill([
                'delivery_status' => LoginConfirmationDeliveryStatus::SENT->value,
                'delivery_status_changed_at' => now(),
                'failure_key' => null,
            ])->save();
        } catch (Throwable) {
            $confirmation->forceFill([
                'delivery_status' => LoginConfirmationDeliveryStatus::FAILED->value,
                'delivery_status_changed_at' => now(),
                'failure_key' => 'mail_transport_failed',
            ])->save();
            Log::warning('Login confirmation delivery failed.', [
                'challenge_id' => $this->challengeId,
                'revision' => $this->revision,
            ]);
        }
    }
}
