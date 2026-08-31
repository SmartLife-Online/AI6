<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\PublishCandidate;
use App\AI6\HumanLoop\GateEvidenceHumanRequestBinding;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\GateState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\RunCancellationMode;
use App\AI6\Runs\RunCancellationService;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class CandidateGateInteractionTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_the_real_answer_route_offers_and_authorizes_gate_evidence_only_for_an_approver(): void
    {
        Mail::fake();
        $opened = $this->manualGateRequest('AI6-027-TC08-ROUTE');
        $operator = $opened['operator'];
        $approver = ProjectMembership::query()->where('project_id', $opened['run']->project_id)
            ->where('role', ProjectRole::APPROVER->value)->firstOrFail()->user()->firstOrFail();

        $this->actingAs($operator)->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]))
            ->assertOk()->assertDontSee('value="authorize_gate_evidence"', false);
        $this->actingAs($approver)->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]))
            ->assertOk()->assertSee('value="authorize_gate_evidence"', false);

        $this->postGateAnswer($operator, $opened)->assertSessionHasErrors('chosen_effect');
        self::assertSame(GateState::OPEN, $opened['gate']->fresh()->state);

        $this->postGateAnswer($approver, $opened)->assertRedirect();
        $closedGate = $opened['gate']->fresh();
        self::assertSame(GateState::CLOSED, $closedGate->state);
        self::assertSame('panel_manual_confirmation', $closedGate->evidence_source);
        self::assertNotNull($closedGate->evidence_observed_at);
        self::assertSame(HumanRequestResolutionState::ANSWERED, $opened['request']->fresh()->resolution_state);
        self::assertSame(ExecutionJobState::PLANNED, $opened['job']->fresh()->state);
    }

    public function test_external_gate_evidence_requires_and_persists_typed_provenance(): void
    {
        Mail::fake();
        $opened = $this->manualGateRequest('AI6-027-EXT', 'EXT-01');
        $approver = ProjectMembership::query()->where('project_id', $opened['run']->project_id)
            ->where('role', ProjectRole::APPROVER->value)->firstOrFail()->user()->firstOrFail();

        $this->actingAs($approver)->get(route('projects.human-requests.show', [$opened['project'], $opened['request']->id]))
            ->assertOk()
            ->assertSee('name="evidence_source"', false)
            ->assertSee('name="evidence_observed_at"', false)
            ->assertSee('name="evidence_digest"', false);

        $this->postGateAnswer($approver, $opened)->assertSessionHasErrors('chosen_effect');
        self::assertSame(GateState::OPEN, $opened['gate']->fresh()->state);

        $observedAt = now()->subMinute()->startOfSecond();
        $digest = str_repeat('a', 64);
        $this->postGateAnswer($approver, $opened, [
            'evidence_source' => 'external_protocol_registry',
            'evidence_observed_at' => $observedAt->toIso8601String(),
            'evidence_digest' => 'sha256:'.$digest,
        ])->assertRedirect();

        $closedGate = $opened['gate']->fresh();
        self::assertSame(GateState::CLOSED, $closedGate->state);
        self::assertSame('external_protocol_registry', $closedGate->evidence_source);
        self::assertSame($observedAt->toIso8601String(), $closedGate->evidence_observed_at?->toIso8601String());
        self::assertSame($digest, $closedGate->evidence_digest);
    }

    public function test_cancel_resolves_a_manual_gate_wait_without_candidate_binding(): void
    {
        Mail::fake();
        $opened = $this->manualGateRequest('AI6-027-TC08-CANCEL');
        $readModel = TicketReadModel::query()->where('project_id', $opened['project']->id)->firstOrFail();
        $inProgress = str_replace('status: ready', 'status: in_progress', $readModel->redacted_content);
        self::assertSame(1, TicketReadModel::query()->whereKey($readModel->id)->update([
            'control_operation_id' => $opened['run']->status_operation_id,
            'control_commit' => $opened['project']->control_oid,
            'redacted_content' => $inProgress,
            'blob_sha' => hash('sha256', 'blob '.strlen($inProgress)."\0".$inProgress),
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));

        $authorization = $this->freshAuthorization(
            $opened['operator'],
            $opened['request']->run_id,
            $opened['request']->id,
            $opened['request']->bound_run_version,
            RunCancellationMode::SOFT->value,
        );
        $this->app->make(RunCancellationService::class)->request(
            $opened['request'],
            $opened['operator'],
            $opened['request']->bound_run_version,
            RunCancellationMode::SOFT,
            'Finalisierung kontrolliert abbrechen.',
            $authorization,
        );

        self::assertSame(HumanRequestResolutionState::CANCELLED, $opened['request']->fresh()->resolution_state);
        self::assertNotNull($opened['run']->fresh()->pending_status_operation_id);
        self::assertNull($opened['run']->fresh()->candidate_tree_sha);
        self::assertNull($opened['run']->fresh()->candidate_diff_hash);
    }

    /** @return array{run: Run, project: Project, operator: User, request: HumanRequest, gate: RunGate, job: ExecutionJob} */
    private function manualGateRequest(string $ticketId, string $gateId = 'MG-01'): array
    {
        $fixture = $this->preparedImplementationRun($ticketId);
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket($ticketId, $gateId)));
        $gate = RunGate::query()->where('run_id', $run->id)->where('gate_id', $gateId)->firstOrFail();
        $job = ExecutionJob::query()->create([
            'run_id' => $run->id, 'step_type' => ExecutionStepType::FINALIZE->value, 'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::FINALIZE, 1),
            'state' => ExecutionJobState::PLANNED,
        ]);
        $candidate = new PublishCandidate(str_repeat('7', 64), str_repeat('8', 64), $run->run_base_sha);
        $request = $this->app->make(HumanRequestService::class)->openManualGateRequest($run, $job, $gate, $candidate);

        return [...$fixture, 'run' => $run, 'request' => $request, 'gate' => $gate, 'job' => $job];
    }

    /**
     * @param  array{run: Run, project: Project, operator: User, request: HumanRequest, gate: RunGate, job: ExecutionJob}  $opened
     * @param  array{evidence_source?: string, evidence_observed_at?: string, evidence_digest?: string}  $evidence
     * @return TestResponse<Response>
     */
    private function postGateAnswer(User $actor, array $opened, array $evidence = []): TestResponse
    {
        $request = $opened['request']->fresh();
        $this->actingAs($actor);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);
        $session->save();

        return $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [$opened['project'], $request->id]), [...[
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => GateEvidenceHumanRequestBinding::EFFECT,
            ], ...$evidence]);
    }

    private function freshAuthorization(User $actor, string $runId, string $requestId, int $version, string $effect): InterventionAuthorization
    {
        $proof = Request::create('/human-request', 'POST');
        $this->startSession();
        $proof->setLaravelSession($this->app->make('session')->driver());
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);

        return InterventionAuthorization::consumeFresh(
            $proof, $actor, $guard, HumanRequestAnswerController::STEP_UP_ACTION,
            [$runId, $requestId, $version, $effect],
        );
    }

    private function gateTicket(string $ticketId, string $gateId): string
    {
        return <<<MARKDOWN
            ---
            schema: ai6.ticket.v1
            id: {$ticketId}
            title: "Gate-Test"
            status: ready
            depends_on: []
            ---

            ## Goal

            Gatebindung prüfen.

            ## Manual and External Gates

            - **{$gateId}** Menschliche oder externe Prüfung.
            MARKDOWN;
    }
}
