<?php

namespace Tests\Unit\HumanLoop;

use App\AI6\HumanLoop\GateEvidenceHumanRequestBinding;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\HumanLoop\SecurityGateHumanRequestBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestBindingTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    public function test_candidate_bindings_distinguish_both_git_formats_from_sha256_checksums(): void
    {
        foreach ([40, 64] as $length) {
            $tree = str_repeat('a', $length);
            $base = str_repeat('b', $length);
            $hash = str_repeat('c', 64);
            $request = new HumanRequest([
                'bound_agent_slot' => GateEvidenceHumanRequestBinding::agentSlot('MG-01'),
                'allowed_effects' => [GateEvidenceHumanRequestBinding::EFFECT],
                'bound_requested_effect' => GateEvidenceHumanRequestBinding::requestedEffect($tree, $hash),
            ]);
            self::assertSame(['gate_id' => 'MG-01', 'tree_oid' => $tree, 'diff_hash' => $hash], GateEvidenceHumanRequestBinding::binding($request));
            foreach ([str_repeat('0', $length).':'.$hash, $tree.':'.str_repeat('c', 40), strtoupper($tree).':'.$hash, $tree.':'.$hash."\n"] as $invalid) {
                $request->bound_requested_effect = $invalid;
                self::assertNull(GateEvidenceHumanRequestBinding::binding($request));
            }
            $request->bound_agent_slot = SecurityGateHumanRequestBinding::agentSlot('fake');
            $request->allowed_effects = [SecurityGateHumanRequestBinding::EFFECT];
            $parts = [$tree, $hash, $base, $hash, $hash, 'fake'];
            $request->bound_requested_effect = implode(':', $parts);
            self::assertNotNull(SecurityGateHumanRequestBinding::binding($request));
            foreach ([0 => str_repeat('0', $length), 1 => str_repeat('c', 40), 2 => str_repeat('b', $length === 40 ? 64 : 40), 3 => str_repeat('c', 40), 4 => str_repeat('c', 40), 5 => 'other'] as $index => $invalid) {
                $changed = $parts;
                $changed[$index] = $invalid;
                $request->bound_requested_effect = implode(':', $changed);
                self::assertNull(SecurityGateHumanRequestBinding::binding($request));
            }
        }
    }

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

        // AI6-051/TC-03: persisted SHA-256 approvals, operations and human bindings
        // remain readable by their real continuation after a schema round trip.
        $before = [];
        foreach (['ticket_approvals', 'control_operations', 'ticket_mutations', 'runs', 'human_requests'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $migration = require base_path('database/migrations/2026_09_24_000000_add_git_object_format_contract.php');
        foreach (['down', 'up', 'down', 'up'] as $method) {
            self::assertIsCallable([$migration, $method]);
            call_user_func([$migration, $method]);
        }
        foreach ($before as $table => $bytes) {
            self::assertSame($bytes, DB::table($table)->get()->toJson());
        }
        $request->refresh();

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

    /** TC-07: the same effect twice yields exactly one effective entry and one effect. */
    public function test_the_same_effect_twice_is_idempotent(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-026-IDEM');
        $request = $opened['request'];
        $answer = function () use ($request, $opened): void {
            $this->app->make(HumanRequestService::class)->answer(
                $request->fresh() ?? $request,
                $opened['operator'],
                $request->bound_run_version,
                $request->bound_ticket_contract,
                $request->bound_checkpoint,
                $request->bound_scope,
                $request->bound_agent_slot,
                $request->bound_requested_effect,
                'a',
            );
        };

        $answer();
        try {
            $answer();
            self::fail('A second identical intervention was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('request_already_resolved', $rejected->reason);
        }

        self::assertSame(1, Intervention::query()->where('human_request_id', $request->id)->count());
        self::assertSame('answered', $request->fresh()->resolution_state->value);
        self::assertSame('running', $opened['run']->fresh()->state->value);
    }
}
