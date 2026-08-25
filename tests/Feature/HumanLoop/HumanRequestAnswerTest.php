<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class HumanRequestAnswerTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-05 */
    public function test_a_later_wait_creates_a_new_request_and_leaves_the_first_history_intact(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-SEQ');
        $first = $opened['request'];
        $this->app->make(HumanRequestService::class)->answer(
            $first,
            $opened['operator'],
            $first->bound_run_version,
            $first->bound_ticket_contract,
            $first->bound_checkpoint,
            $first->bound_scope,
            $first->bound_agent_slot,
            $first->bound_requested_effect,
            'a',
        );
        $first = $first->fresh();
        $firstIntervention = Intervention::query()->where('human_request_id', $first->id)->sole();
        $firstDelivery = $first->delivery_status;

        $second = $this->app->make(HumanRequestService::class)->open(
            $opened['run']->refresh(),
            $this->humanRequestProposal('Zweite Frage'),
            $opened['slot'],
            $first->bound_step_key,
        );

        self::assertNotSame($first->id, $second->id);
        self::assertSame($first->title, $first->fresh()->title);
        self::assertSame($firstIntervention->id, Intervention::query()->where('human_request_id', $first->id)->sole()->id);
        self::assertSame($firstDelivery, $first->fresh()->delivery_status);
        self::assertSame(2, HumanRequest::query()->where('run_id', $opened['run']->id)->count());
    }

    /** TC-07 */
    public function test_a_second_answer_creates_no_second_intervention_or_resume(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-DUP');
        $request = $opened['request'];
        $service = $this->app->make(HumanRequestService::class);
        $first = $service->answer(
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

        try {
            $service->answer(
                $request->fresh(),
                $opened['attention'],
                $request->bound_run_version,
                $request->bound_ticket_contract,
                $request->bound_checkpoint,
                $request->bound_scope,
                $request->bound_agent_slot,
                $request->bound_requested_effect,
                'b',
            );
            self::fail('The second answer must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('request_already_resolved', $rejected->reason);
        }

        self::assertSame(1, Intervention::query()->where('human_request_id', $request->id)->count());
        $audit = Intervention::query()->where('human_request_id', $request->id)->sole();
        self::assertSame($first->id, $audit->id);
        self::assertSame('operator', $audit->actor_role);
        self::assertFalse($audit->step_up_verified);
        self::assertNull($audit->step_up_proof_hash);
        self::assertSame($request->bound_run_version, $audit->expected_run_version);
        self::assertSame('human_question', $audit->wait_reason);
        self::assertSame($request->bound_step_key, $audit->bound_step_key);
        self::assertNotSame('', trim((string) $audit->reason));
        try {
            DB::table('interventions')->where('id', $audit->id)->update(['reason' => 'manipuliert']);
            self::fail('The intervention audit was mutable.');
        } catch (QueryException) {
            self::assertSame('Gebundene Panelantwort.', $audit->fresh()->reason);
        }
        try {
            DB::table('interventions')->where('id', $audit->id)->delete();
            self::fail('The intervention audit could be deleted.');
        } catch (QueryException) {
            self::assertSame($audit->id, $audit->fresh()->id);
        }
        self::assertSame(1, ExecutionJob::query()->where('run_id', $opened['run']->id)
            ->where('idempotency_key', $request->bound_step_key)
            ->where('state', ExecutionJobState::PLANNED)->count());
    }

    /** TC-08 */
    public function test_unentitled_users_see_neither_content_nor_effect(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-018-AUTH');
        $viewer = $this->createUser();
        $this->addMembership($viewer, $opened['project'], ProjectRole::VIEWER);
        $stranger = $this->createUser();
        $other = $this->createUser();
        $foreign = $this->createProject('Fremdprojekt '.bin2hex(random_bytes(4)));
        $this->addMembership($other, $foreign, ProjectRole::ADMIN);

        $this->actingAs($viewer)
            ->get(route('human-requests.index'))
            ->assertOk()
            ->assertDontSee($opened['request']->title)
            ->assertSee('Keine offenen Anfragen');
        $this->actingAs($viewer)
            ->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]))
            ->assertForbidden()
            ->assertDontSee($opened['request']->title);
        $this->actingAs($stranger)
            ->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]))
            ->assertForbidden();
        $this->actingAs($other)
            ->get(route('projects.human-requests.show', [$foreign, $opened['request']->id]))
            ->assertNotFound();

        $this->actingAs($viewer)->post(route('projects.human-requests.answer', [$opened['project'], $opened['request']->id]), [
            'run_version' => $opened['request']->bound_run_version,
            'ticket_contract' => $opened['request']->bound_ticket_contract,
            'checkpoint' => $opened['request']->bound_checkpoint,
            'scope' => $opened['request']->bound_scope,
            'agent_slot' => $opened['request']->bound_agent_slot,
            'requested_effect' => $opened['request']->bound_requested_effect,
            'chosen_effect' => 'a',
        ])->assertForbidden();

        self::assertSame(0, Intervention::query()->where('human_request_id', $opened['request']->id)->count());
        self::assertSame('open', $opened['request']->fresh()->resolution_state->value);
    }

    /** AC-07 — HUM-004 verlangt eine vollstaendige Bindung, kein leeres Feld. */
    public function test_a_run_without_a_bound_checkpoint_cannot_open_a_request(): void
    {
        Mail::fake();

        try {
            $this->openedHumanRequest('AI6-018-CKP', bindCheckpoint: false);
            self::fail('A run without a bound checkpoint must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('checkpoint_not_bound', $rejected->reason);
        }

        self::assertSame(0, HumanRequest::query()->count());
        Mail::assertNothingSent();
        $run = Run::query()->sole();
        self::assertSame(RunState::RUNNING, $run->state);
        self::assertNull($run->wait_reason);
    }

    public function test_a_running_leased_step_is_parked_and_an_unparkable_step_is_rejected(): void
    {
        Mail::fake();
        $this->bindInstructionSource();
        $attention = $this->createUser(['email' => 'attention-park@example.test']);
        $fixture = $this->completedApproval('AI6-018-PRK', attentionUser: $attention);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $claimed = $this->app->make(RunOrchestrator::class)->claimStep($step, 'worker:test:lease');
        self::assertInstanceOf(ExecutionJob::class, $claimed);

        $request = $this->app->make(HumanRequestService::class)->open(
            $run->refresh(),
            $this->humanRequestProposal(),
            $claimed->id.'-slot',
            $claimed->idempotency_key,
        );
        self::assertSame(ExecutionJobState::WAITING, $claimed->fresh()->state);
        self::assertSame('open', $request->resolution_state->value);

        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $fixture['operator'],
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'a',
        );

        ExecutionJob::query()->where('idempotency_key', $claimed->idempotency_key)->update([
            'state' => ExecutionJobState::RUNNING,
            'lease_owner' => null,
        ]);
        try {
            $this->app->make(HumanRequestService::class)->open(
                $run->refresh(),
                $this->humanRequestProposal('Zweite Frage'),
                $request->bound_agent_slot,
                $claimed->idempotency_key,
            );
            self::fail('An unparkable bound step must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('bound_step_not_parkable', $rejected->reason);
        }
        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)->where('resolution_state', 'open')->count());
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
    }

    public function test_a_request_without_a_bound_attention_user_is_rejected(): void
    {
        Mail::fake();
        $this->bindInstructionSource();
        $fixture = $this->completedApproval('AI6-018-ATT');
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();

        try {
            $this->app->make(HumanRequestService::class)->open(
                $run,
                $this->humanRequestProposal(),
                'slot-without-attention',
                $step->idempotency_key,
            );
            self::fail('A missing attention user must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('attention_user_unavailable', $rejected->reason);
        }

        self::assertSame(0, HumanRequest::query()->count());
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
    }

    /** AC-03 — der Empfänger muss die Anfrage auch beantworten dürfen. */
    public function test_an_attention_user_without_the_answer_permission_is_rejected(): void
    {
        Mail::fake();
        $this->bindInstructionSource();
        $powerless = $this->createUser(['email' => 'viewer-attention@example.test']);
        $fixture = $this->completedApproval('AI6-018-VIEW', attentionUser: $powerless);
        // The approval only binds an active member; the answer permission is a
        // server decision this module makes for itself.
        ProjectMembership::query()
            ->where('user_id', $powerless->getKey())
            ->where('project_id', $fixture['project']->getKey())
            ->update(['role' => ProjectRole::VIEWER]);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();

        try {
            $this->app->make(HumanRequestService::class)->open(
                $run,
                $this->humanRequestProposal(),
                'slot-powerless-attention',
                $step->idempotency_key,
            );
            self::fail('An attention user without the answer permission must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('attention_user_unavailable', $rejected->reason);
        }

        self::assertSame(0, HumanRequest::query()->count());
        Mail::assertNothingSent();
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
    }

    /** AC-11 — die Inbox ist eine Leseoberfläche mit zeilenunabhängiger Abfragezahl. */
    public function test_the_inbox_reads_runs_and_approvals_in_one_batch_each(): void
    {
        Mail::fake();
        $first = $this->openedHumanRequest('AI6-018-NP1');
        // The shared project fixture mints one fixed identifier, so the first
        // project releases it before the second run is built.
        Project::query()->whereKey($first['project']->getKey())
            ->update(['project_identifier' => str_repeat('b', 32)]);
        $second = $this->openedHumanRequest('AI6-018-NP2');
        $this->addMembership($first['operator'], $second['project'], ProjectRole::OPERATOR);

        DB::enableQueryLog();
        $response = $this->actingAs($first['operator'])->get(route('human-requests.index'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk()
            ->assertSee($first['request']->fresh()->title)
            ->assertSee($second['request']->fresh()->title);

        $count = static fn (string $table): int => count(array_filter(
            $queries,
            static fn (array $query): bool => str_contains((string) $query['query'], 'from "'.$table.'"'),
        ));
        self::assertSame(1, $count('runs'), 'The inbox must read every run in a single batch.');
        self::assertSame(1, $count('ticket_approvals'), 'The inbox must read every approval in a single batch.');
    }

    private function bindInstructionSource(): void
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    /** TC-11 / TC-12 */
    public function test_inbox_and_detail_stay_inside_the_fixed_csp_and_hide_redacted_secrets(): void
    {
        Mail::fake();
        $secret = 'supersecretvalue';
        $opened = $this->openedHumanRequest('AI6-018-UI', $this->humanRequestProposal(
            'Titel secret='.$secret,
            'Nachricht secret='.$secret,
            'Begründung secret='.$secret,
            'Label secret='.$secret,
            '/home/alice/project/app/Example.php',
        ));

        $policy = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
            ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
            ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

        $inbox = $this->actingAs($opened['operator'])->get(route('human-requests.index'));
        $inbox->assertOk();
        $inbox->assertSee($opened['project']->name);
        $inbox->assertSee($opened['request']->fresh()->title);
        $inbox->assertSee('human_question');
        $inbox->assertSee('data-delivery-status', false);
        $inbox->assertDontSee($secret);
        $inbox->assertSee(RedactionMatchType::SECRET->marker());
        $inbox->assertHeader('Content-Security-Policy', $policy);

        $detail = $this->actingAs($opened['operator'])
            ->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]));
        $detail->assertOk();
        $detail->assertSee('Nachricht');
        $detail->assertSee('name="chosen_effect"', false);
        $detail->assertSee('value="soft_cancel"', false);
        // The decision buttons carry the redacted German option label, not the
        // raw provider key the effect binding is built from.
        $detail->assertSee('value="a">'.$opened['request']->fresh()->options[0]['label'].'</button>', false);
        $detail->assertSee('value="b">Option B</button>', false);
        $detail->assertDontSee($secret);
        $detail->assertDontSee('/home/alice/project/app/Example.php');
        $detail->assertHeader('Content-Security-Policy', $policy);

        $inboxBody = (string) $inbox->getContent();
        $detailBody = (string) $detail->getContent();
        foreach ([$inboxBody, $detailBody] as $body) {
            self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/i', $body));
            self::assertSame(0, preg_match('/<style|\sstyle\s*=/i', $body));
            // A CSS width can no longer appear here at all — the assertion above
            // already forbids every inline style — so the layout check has to
            // look at markup that this page could actually emit.
            self::assertSame(0, preg_match('/<table\b/i', $body));
            self::assertSame(0, preg_match('/\swidth\s*=\s*"\d{3,}/i', $body));
            self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        }
    }
}
