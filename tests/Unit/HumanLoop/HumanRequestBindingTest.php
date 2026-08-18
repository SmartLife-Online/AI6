<?php

namespace Tests\Unit\HumanLoop;

use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\Intervention;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestBindingTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function staleBindingProvider(): iterable
    {
        yield 'run version' => ['runVersion', 'stale_run_version', 'AI6-018-B1'];
        yield 'ticket contract' => ['ticketContract', 'stale_ticket_contract', 'AI6-018-B2'];
        yield 'checkpoint' => ['checkpoint', 'stale_checkpoint', 'AI6-018-B3'];
        yield 'scope' => ['scope', 'stale_scope', 'AI6-018-B4'];
        yield 'agent slot' => ['agentSlot', 'stale_agent_slot', 'AI6-018-B5'];
        yield 'requested effect' => ['requestedEffect', 'stale_requested_effect', 'AI6-018-B6'];
        yield 'unoffered effect' => ['chosenEffect', 'effect_not_offered', 'AI6-018-B7'];
    }

    /** TC-06 */
    #[DataProvider('staleBindingProvider')]
    public function test_a_stale_or_unoffered_binding_is_rejected_without_an_intervention(string $field, string $reason, string $ticketId): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest($ticketId);
        $request = $opened['request'];
        $payload = [
            'runVersion' => $request->bound_run_version,
            'ticketContract' => $request->bound_ticket_contract,
            'checkpoint' => $request->bound_checkpoint,
            'scope' => $request->bound_scope,
            'agentSlot' => $request->bound_agent_slot,
            'requestedEffect' => $request->bound_requested_effect,
            'chosenEffect' => 'a',
        ];
        $payload[$field] = $field === 'runVersion' ? $request->bound_run_version + 1 : 'stale-'.$field;

        try {
            $this->app->make(HumanRequestService::class)->answer(
                $request,
                $opened['operator'],
                $payload['runVersion'],
                $payload['ticketContract'],
                $payload['checkpoint'],
                $payload['scope'],
                $payload['agentSlot'],
                $payload['requestedEffect'],
                $payload['chosenEffect'],
            );
            self::fail('Expected a binding rejection.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame($reason, $rejected->reason);
        }

        self::assertSame(0, Intervention::query()->where('human_request_id', $request->id)->count());
        self::assertSame('open', $request->fresh()->resolution_state->value);
        self::assertSame('waiting', $opened['run']->fresh()->state->value);
    }

    /** TC-06 */
    public function test_a_matching_binding_is_accepted(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-BND-OK');
        $request = $opened['request'];

        $intervention = $this->app->make(HumanRequestService::class)->answer(
            $request,
            $opened['operator'],
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'a',
        );

        self::assertSame('a', $intervention->chosen_effect);
        self::assertSame('answered', $request->fresh()->resolution_state->value);
        self::assertSame('running', $opened['run']->fresh()->state->value);
    }
}
