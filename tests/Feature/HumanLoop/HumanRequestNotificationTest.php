<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\HumanLoop\HumanRequestDeliveryStatus;
use App\AI6\HumanLoop\HumanRequestNotificationConfiguration;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Jobs\SendHumanRequestNotification;
use App\AI6\HumanLoop\Mail\HumanRequestNotificationMail;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestNotificationTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-01 */
    public function test_a_new_request_sends_exactly_one_mail_to_the_bound_attention_user(): void
    {
        Mail::fake();
        config(['ai6.auth.login_confirmation_email' => 'login-only@example.test']);
        $this->app->forgetInstance(AuthConfiguration::class);

        $opened = $this->openedHumanRequest('AI6-018-MAIL-1', $this->humanRequestProposal(
            'Frage',
            'Bitte entscheiden. leaked@ticket.example',
            'Begründung',
        ));

        Mail::assertSent(HumanRequestNotificationMail::class, 1);
        Mail::assertSent(HumanRequestNotificationMail::class, function (HumanRequestNotificationMail $mail) use ($opened): bool {
            return $mail->hasTo($opened['attention']->email)
                && ! $mail->hasTo('login-only@example.test')
                && ! $mail->hasTo('leaked@ticket.example');
        });
        self::assertSame(HumanRequestDeliveryStatus::SENT, $opened['request']->fresh()->delivery_status);
    }

    /** TC-02 */
    public function test_a_mail_failure_retries_without_changing_run_or_wait_state(): void
    {
        config([
            'mail.default' => 'array',
            'ai6.human_requests.notification_max_attempts' => '2',
            'ai6.human_requests.notification_retry_seconds' => '1',
        ]);
        $this->app->forgetInstance(HumanRequestNotificationConfiguration::class);

        try {
            $this->openedHumanRequest('AI6-018-MAIL-2');
            self::fail('The failing transport must retry.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('will be retried', $exception->getMessage());
        }

        $request = HumanRequest::query()->sole();
        $run = $request->run()->firstOrFail();
        self::assertSame(HumanRequestDeliveryStatus::FAILED, $request->delivery_status);
        self::assertSame('mail_transport_not_deliverable', $request->delivery_failure_key);
        self::assertSame(1, $request->delivery_attempts);
        self::assertSame(HumanRequestResolutionState::OPEN, $request->resolution_state);
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::HUMAN_QUESTION, $run->wait_reason);

        (new SendHumanRequestNotification($request->id, $request->delivery_revision))->handle();
        $request = $request->fresh();
        self::assertSame(2, $request->delivery_attempts);
        self::assertSame(HumanRequestDeliveryStatus::FAILED, $request->delivery_status);
        self::assertSame(HumanRequestResolutionState::OPEN, $request->resolution_state);

        (new SendHumanRequestNotification($request->id, $request->delivery_revision))->handle();
        $request = $request->fresh();
        self::assertSame(2, $request->delivery_attempts);
        self::assertSame(HumanRequestDeliveryStatus::FAILED, $request->delivery_status);
        self::assertSame(RunState::WAITING, $run->fresh()->state);
    }

    /** TC-02 */
    public function test_a_transport_exception_sets_mail_transport_failed_and_retries(): void
    {
        config([
            'mail.default' => 'smtp',
            'ai6.human_requests.notification_max_attempts' => '2',
            'ai6.human_requests.notification_retry_seconds' => '1',
        ]);
        $this->app->forgetInstance(HumanRequestNotificationConfiguration::class);
        // A real transport that fails on send, so the branch runs through the
        // Mailer and the rendered Mailable instead of a stubbed facade.
        Mail::extend('ai6-failing-transport', static fn (): TransportInterface => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('smtp transport failed');
            }

            public function __toString(): string
            {
                return 'ai6-failing-transport://';
            }
        });
        config(['mail.mailers.smtp' => ['transport' => 'ai6-failing-transport']]);
        Mail::forgetMailers();

        try {
            $this->openedHumanRequest('AI6-018-MAIL-5');
            self::fail('The failing transport must retry.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('will be retried', $exception->getMessage());
        }

        $request = HumanRequest::query()->sole();
        $run = $request->run()->firstOrFail();
        self::assertSame(HumanRequestDeliveryStatus::FAILED, $request->delivery_status);
        self::assertSame('mail_transport_failed', $request->delivery_failure_key);
        self::assertSame(1, $request->delivery_attempts);
        self::assertSame(HumanRequestResolutionState::OPEN, $request->resolution_state);
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(WaitReason::HUMAN_QUESTION, $run->wait_reason);

        (new SendHumanRequestNotification($request->id, $request->delivery_revision))->handle();
        $request = $request->fresh();
        self::assertSame(2, $request->delivery_attempts);
        self::assertSame(HumanRequestDeliveryStatus::FAILED, $request->delivery_status);
        self::assertSame(RunState::WAITING, $run->fresh()->state);
    }

    /** TC-03 */
    public function test_the_same_notification_is_sent_only_once(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-MAIL-3');
        Mail::assertSent(HumanRequestNotificationMail::class, 1);

        (new SendHumanRequestNotification($opened['request']->id, $opened['request']->delivery_revision))->handle();

        Mail::assertSent(HumanRequestNotificationMail::class, 1);
        self::assertSame(HumanRequestDeliveryStatus::SENT, $opened['request']->fresh()->delivery_status);
    }

    /** TC-13 */
    public function test_the_mail_link_requires_a_session_and_carries_no_action(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-MAIL-4');
        $url = route('projects.human-requests.show', [$opened['project'], $opened['request']->id]);

        Mail::assertSent(HumanRequestNotificationMail::class, function (HumanRequestNotificationMail $mail) use ($url): bool {
            $rendered = $mail->render();

            return str_contains($rendered, $url)
                && ! str_contains($rendered, 'method="post"')
                && ! str_contains($rendered, 'chosen_effect');
        });

        $this->get($url)->assertRedirect(route('login'));
        self::assertSame(HumanRequestResolutionState::OPEN, $opened['request']->fresh()->resolution_state);
        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
    }

    public function test_a_stale_delivery_revision_is_discarded(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-MAIL-6');
        Mail::assertSent(HumanRequestNotificationMail::class, 1);
        $staleRevision = $opened['request']->delivery_revision;

        $this->app->make(HumanRequestService::class)->redispatchNotification($opened['request']);
        Mail::assertSent(HumanRequestNotificationMail::class, 2);
        self::assertSame($staleRevision + 1, $opened['request']->fresh()->delivery_revision);

        (new SendHumanRequestNotification($opened['request']->id, $staleRevision))->handle();
        Mail::assertSent(HumanRequestNotificationMail::class, 2);
        self::assertSame(HumanRequestDeliveryStatus::SENT, $opened['request']->fresh()->delivery_status);
    }
}
