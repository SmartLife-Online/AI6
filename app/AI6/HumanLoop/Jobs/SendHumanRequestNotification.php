<?php

namespace App\AI6\HumanLoop\Jobs;

use App\AI6\HumanLoop\HumanRequestDeliveryStatus;
use App\AI6\HumanLoop\HumanRequestNotificationConfiguration;
use App\AI6\HumanLoop\HumanRequestRecipient;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\Mail\HumanRequestNotificationMail;
use App\AI6\HumanLoop\Models\HumanRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendHumanRequestNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $requestId,
        private readonly int $revision,
    ) {}

    public function tries(): int
    {
        return app(HumanRequestNotificationConfiguration::class)->maxAttempts;
    }

    public function backoff(): int
    {
        return app(HumanRequestNotificationConfiguration::class)->retrySeconds;
    }

    public function handle(): void
    {
        $retry = DB::transaction(function (): bool {
            DB::table('human_requests')->where('id', $this->requestId)->lockForUpdate()->first();
            $request = HumanRequest::query()->find($this->requestId);
            if (! $request instanceof HumanRequest
                || $request->delivery_revision !== $this->revision
                || $request->resolution_state !== HumanRequestResolutionState::OPEN
                || $request->delivery_status === HumanRequestDeliveryStatus::SENT) {
                return false;
            }

            $configuration = app(HumanRequestNotificationConfiguration::class);
            if ($request->delivery_attempts >= $configuration->maxAttempts) {
                return false;
            }

            $recipient = app(HumanRequestRecipient::class)->resolve($request->attention_user_id, $request->project_id);
            if ($recipient === null) {
                $this->markFailed($request, 'attention_user_unavailable');

                return $this->shouldRetry($request, $configuration);
            }

            if (config('mail.default') !== 'smtp') {
                $this->markFailed($request, 'mail_transport_not_deliverable');

                return $this->shouldRetry($request, $configuration);
            }

            try {
                Mail::to($recipient)->send(new HumanRequestNotificationMail($request));
                $request->forceFill([
                    'delivery_status' => HumanRequestDeliveryStatus::SENT,
                    'delivery_status_changed_at' => now(),
                    'delivery_failure_key' => null,
                    'delivery_attempts' => $request->delivery_attempts + 1,
                ])->save();

                return false;
            } catch (Throwable) {
                $this->markFailed($request, 'mail_transport_failed');
                Log::warning('Human request notification delivery failed.', [
                    'request_id' => $this->requestId,
                    'revision' => $this->revision,
                ]);

                return $this->shouldRetry($request, $configuration);
            }
        });

        if ($retry) {
            throw new \RuntimeException('Human request notification delivery will be retried.');
        }
    }

    private function markFailed(HumanRequest $request, string $failureKey): void
    {
        $request->forceFill([
            'delivery_status' => HumanRequestDeliveryStatus::FAILED,
            'delivery_status_changed_at' => now(),
            'delivery_failure_key' => $failureKey,
            'delivery_attempts' => $request->delivery_attempts + 1,
        ])->save();
    }

    private function shouldRetry(HumanRequest $request, HumanRequestNotificationConfiguration $configuration): bool
    {
        return $request->delivery_attempts < $configuration->maxAttempts
            && $request->resolution_state === HumanRequestResolutionState::OPEN
            && $request->delivery_status !== HumanRequestDeliveryStatus::SENT;
    }
}
