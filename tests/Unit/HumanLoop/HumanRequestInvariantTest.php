<?php

namespace Tests\Unit\HumanLoop;

use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Mail\HumanRequestNotificationMail;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestInvariantTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-04 */
    public function test_a_second_open_request_is_rejected_by_the_database_without_mail_or_state_change(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-INV');
        $before = $opened['run']->refresh();

        try {
            $this->app->make(HumanRequestService::class)->open(
                $before,
                $this->humanRequestProposal('Zweite Frage'),
                $opened['slot'],
                $opened['request']->bound_step_key,
            );
            self::fail('Expected the second open to be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('open_request_exists', $rejected->reason);
        }

        self::assertSame(1, HumanRequest::query()->where('run_id', $before->id)->count());
        self::assertSame(RunState::WAITING, $before->refresh()->state);
        self::assertSame(WaitReason::HUMAN_QUESTION, $before->wait_reason);
        self::assertSame($opened['request']->bound_run_version, $before->version);
        Mail::assertSent(HumanRequestNotificationMail::class, 1);

        $duplicate = (array) DB::table('human_requests')->where('id', $opened['request']->id)->first();
        $duplicate['id'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $duplicate['title'] = 'Direktinsert';
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('human_requests')->insert($duplicate);
    }
}
