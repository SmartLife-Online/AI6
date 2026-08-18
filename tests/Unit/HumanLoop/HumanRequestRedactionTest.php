<?php

namespace Tests\Unit\HumanLoop;

use App\AI6\HumanLoop\Mail\HumanRequestNotificationMail;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestRedactionTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-11 */
    public function test_untrusted_proposal_text_is_persisted_only_as_central_markers(): void
    {
        Mail::fake();
        $secret = 'supersecretvalue';
        $opened = $this->openedHumanRequest('AI6-018-RED', $this->humanRequestProposal(
            'Titel secret='.$secret,
            'Nachricht secret='.$secret,
            'Begründung secret='.$secret,
            'Label secret='.$secret,
            '/home/alice/project/app/Example.php',
        ));

        $request = HumanRequest::query()->findOrFail($opened['request']->id);
        $payload = json_encode($request->getAttributes(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $payload);
        self::assertStringNotContainsString('/home/alice/project/app/Example.php', $payload);
        self::assertStringContainsString(RedactionMatchType::SECRET->marker(), $request->title);
        self::assertStringContainsString(RedactionMatchType::SECRET->marker(), $request->message);
        self::assertStringContainsString(RedactionMatchType::SECRET->marker(), $request->why_needed);
        self::assertStringContainsString(RedactionMatchType::SECRET->marker(), $request->options[0]['label']);
        self::assertStringContainsString(RedactionMatchType::SENSITIVE_PATH->marker(), $request->affected_paths[0]);

        Mail::assertSent(HumanRequestNotificationMail::class, function (HumanRequestNotificationMail $mail) use ($secret): bool {
            $rendered = $mail->render();

            return ! str_contains($rendered, $secret)
                && str_contains($rendered, RedactionMatchType::SECRET->marker());
        });
    }
}
