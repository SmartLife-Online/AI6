<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\PublishCandidate;
use App\AI6\HumanLoop\GateEvidenceHumanRequestBinding;
use App\AI6\HumanLoop\GateEvidenceService;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\GateState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;

final class PublishCandidateGateTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_candidate_gate_evidence_is_exactly_bound_and_scope_change_invalidates_candidate_and_gate(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-027-GATE');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $ticket = $this->app->make(TicketV1Parser::class)->parse($this->gateTicket());
        self::assertSame(['MG-01'], $ticket->manualGateIds);
        $orchestrator->prepareGates($run, $ticket);
        self::assertSame(1, DB::table('run_gates')->where('run_id', $run->id)->count());
        $gateRow = (array) DB::table('run_gates')->where('run_id', $run->id)->first();
        self::assertNotNull($gateRow['ticket_contract_sha256']);
        $candidate = new PublishCandidate(str_repeat('4', 64), str_repeat('5', 64), $run->run_base_sha);
        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);

        $gate = $orchestrator->authorizeCandidateGateEvidence(
            $run,
            'MG-01',
            $approver->id,
            'intervention:test-gate',
            $candidate,
            $run->version + 1,
        );
        self::assertSame(GateState::CLOSED, $gate->state);
        $gateRow = (array) DB::table('run_gates')->where('id', $gate->id)->first();
        self::assertSame($gateRow['ticket_contract_sha256'], $gateRow['evidence_ticket_contract_sha256']);
        self::assertSame($gateRow['ticket_contract_sha256'], $gate->getRawOriginal('evidence_ticket_contract_sha256'));
        self::assertSame($candidate->treeOid, $gate->evidence_candidate_tree_sha);
        self::assertSame($candidate->diffHash, $gate->evidence_candidate_diff_hash);

        $run = $orchestrator->bindCandidate($run, $run->version, $candidate);
        $gate = $gate->fresh();
        self::assertSame($run->version, $gate->evidence_expected_run_version);
        self::assertSame($gateRow['ticket_contract_sha256'], $gate->evidence_ticket_contract_sha256);
        self::assertSame($run->checkpoint_commit_sha, $gate->checkpoint_commit_sha);
        self::assertSame([], $orchestrator->invalidateStaleCandidateGateEvidence($run, $candidate));
        self::assertNull($run->candidate_invalidated_at);

        $changed = new PublishCandidate(str_repeat('6', 64), $candidate->diffHash, $candidate->baseSha);
        self::assertSame(['MG-01'], $orchestrator->invalidateStaleCandidateGateEvidence($run, $changed));
        self::assertSame(GateState::OPEN, $gate->fresh()->state);

        $run = $orchestrator->applyScopeDecision(
            $run,
            'app/Added.php',
            true,
            null,
            12,
            $this->app->make(CanonicalJson::class),
            'auto_allow',
        );

        self::assertNotNull($run->candidate_invalidated_at);
        self::assertSame(GateState::OPEN, RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->firstOrFail()->state);
    }

    public function test_manual_gate_request_authorizes_one_bound_intervention_and_resumes_the_same_step(): void
    {
        Mail::fake();
        $fixture = $this->preparedImplementationRun('AI6-027-GATE-REQUEST');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()));
        $gate = RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->firstOrFail();
        $candidate = new PublishCandidate(str_repeat('7', 64), str_repeat('8', 64), $run->run_base_sha);
        $job = ExecutionJob::query()->create([
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::FINALIZE->value,
            'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::FINALIZE, 1),
            'state' => ExecutionJobState::PLANNED,
        ]);
        $request = $this->app->make(HumanRequestService::class)->openManualGateRequest($run, $job, $gate, $candidate);
        $sameRequest = $this->app->make(HumanRequestService::class)->openManualGateRequest($run, $job, $gate, $candidate);
        self::assertSame($request->id, $sameRequest->id);
        self::assertSame(1, DB::table('human_requests')->where('run_id', $run->id)->where('resolution_state', 'open')->count());
        self::assertSame(GateEvidenceHumanRequestBinding::EFFECT, $request->allowed_effects[0]);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);

        try {
            $this->app->make(HumanRequestService::class)->answer(
                $request,
                $fixture['operator'],
                $request->bound_run_version,
                $request->bound_ticket_contract,
                $request->bound_checkpoint,
                $request->bound_scope,
                $request->bound_agent_slot,
                $request->bound_requested_effect,
                GateEvidenceHumanRequestBinding::EFFECT,
            );
            self::fail('Gate evidence must use the specialized resolver.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('specialized_effect_required', $rejected->reason);
        }

        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $intervention = $this->app->make(GateEvidenceService::class)->authorize(
            $request,
            $approver,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            $this->authorization($approver, $request->run_id, $request->id, $request->bound_run_version),
        );

        self::assertSame(GateEvidenceHumanRequestBinding::EFFECT, $intervention->chosen_effect);
        self::assertTrue($intervention->step_up_verified);
        self::assertSame(1, Intervention::query()->where('human_request_id', $request->id)->count());
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        $gate = $gate->fresh();
        self::assertSame(GateState::CLOSED, $gate->state);
        self::assertSame($candidate->treeOid, $gate->evidence_candidate_tree_sha);
        $resumed = $run->fresh();
        self::assertSame($resumed->version + 1, $gate->evidence_expected_run_version);
        self::assertSame([], $orchestrator->invalidateStaleCandidateGateEvidence($resumed, $candidate));
        $bound = $orchestrator->bindCandidate($resumed, $resumed->version, $candidate);
        self::assertSame($gate->evidence_expected_run_version, $bound->version);
        self::assertSame([], $orchestrator->invalidateStaleCandidateGateEvidence($bound, $candidate));
    }

    public function test_a_prospective_candidate_mismatch_stales_evidence_and_reparks_manual_gate(): void
    {
        Mail::fake();
        $fixture = $this->preparedImplementationRun('AI6-027-TC09');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()));
        $gate = RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->firstOrFail();
        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $expected = new PublishCandidate(str_repeat('7', 64), str_repeat('8', 64), $run->run_base_sha);
        $orchestrator->authorizeCandidateGateEvidence(
            $run, 'MG-01', $approver->id, 'intervention:tc09', $expected, $run->version + 1,
        );

        $actual = new PublishCandidate(str_repeat('9', 64), $expected->diffHash, $expected->baseSha);
        self::assertSame(['MG-01'], $orchestrator->invalidateStaleCandidateGateEvidence($run, $actual));
        self::assertSame(GateState::OPEN, $gate->fresh()->state);
        self::assertNotNull($gate->fresh()->invalidated_at);

        $job = ExecutionJob::query()->create([
            'run_id' => $run->id, 'step_type' => ExecutionStepType::FINALIZE->value, 'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::FINALIZE, 1),
            'state' => ExecutionJobState::PLANNED,
        ]);
        $request = $this->app->make(HumanRequestService::class)->openManualGateRequest(
            $run->fresh(), $job, $gate->fresh(), $actual,
        );
        self::assertSame('manual_gate', $run->fresh()->wait_reason->value);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);
        self::assertSame($actual->treeOid.':'.$actual->diffHash, $request->bound_requested_effect);
        self::assertNull($run->fresh()->candidate_tree_sha);
    }

    #[DataProvider('candidateInvalidationCauseProvider')]
    public function test_each_bound_contract_or_provenance_change_invalidates_candidate_and_gate_evidence(string $cause): void
    {
        $fixture = $this->preparedImplementationRun('AI6-027-TC10-'.strtoupper($cause));
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()));
        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $candidate = new PublishCandidate(str_repeat('a', 64), str_repeat('b', 64), $run->run_base_sha);
        $gate = $orchestrator->authorizeCandidateGateEvidence(
            $run, 'MG-01', $approver->id, 'intervention:tc10', $candidate, $run->version + 1,
        );
        $run = $orchestrator->bindCandidate($run, $run->version, $candidate);

        $run = match ($cause) {
            'ticket' => $orchestrator->applyContractAmendment(
                $run, $run->run_base_sha, str_repeat('c', 64), str_repeat('d', 64),
                (array) $run->scope_snapshot, (string) $run->scope_hash,
                (array) $run->config_snapshot, (string) $run->config_hash,
                (array) $run->prompt_snapshot, (string) $run->prompt_hash,
                $this->app->make(CanonicalJson::class), 12,
            ),
            'prompt' => $orchestrator->applyContractAmendment(
                $run, $run->run_base_sha, str_repeat('c', 64),
                (string) ($run->ticket_contract_sha256
                    ?? DB::table('ticket_approvals')->where('id', $run->ticket_approval_id)->value('ticket_contract_sha256')),
                (array) $run->scope_snapshot, (string) $run->scope_hash,
                (array) $run->config_snapshot, (string) $run->config_hash,
                ['changed' => true], str_repeat('e', 64),
                $this->app->make(CanonicalJson::class), 12,
            ),
            'checkpoint' => $orchestrator->bindCheckpoint(
                $run, $run->version, str_repeat('4', 64), str_repeat('5', 64), str_repeat('6', 64),
            ),
            'security' => $this->changeSecurityPolicyBinding($run, $candidate, $orchestrator),
            default => throw new \LogicException('Unknown candidate invalidation cause.'),
        };

        $freshGate = $gate->fresh();
        self::assertNotNull($run->candidate_invalidated_at);
        self::assertNotNull($run->candidate_tree_sha, 'The invalidated candidate remains readable.');
        self::assertSame(GateState::OPEN, $freshGate->state);
        self::assertNotNull($freshGate->invalidated_at);
        self::assertNotNull($freshGate->evidence_candidate_tree_sha, 'The invalidated evidence remains readable.');
    }

    public function test_fresh_evidence_can_replace_an_already_invalidated_candidate_binding(): void
    {
        $fixture = $this->preparedImplementationRun('AI6-027-TC10-REAUTHORIZE');
        $run = $fixture['run'];
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $orchestrator->prepareGates($run, $this->app->make(TicketV1Parser::class)->parse($this->gateTicket()));
        $approver = $this->createUser();
        $this->addMembership($approver, $fixture['project'], ProjectRole::APPROVER);
        $oldCandidate = new PublishCandidate(str_repeat('a', 64), str_repeat('b', 64), $run->run_base_sha);
        $orchestrator->authorizeCandidateGateEvidence(
            $run, 'MG-01', $approver->id, 'intervention:tc10-old', $oldCandidate, $run->version + 1,
        );
        $run = $orchestrator->bindCandidate($run, $run->version, $oldCandidate);
        $run = $this->changeSecurityPolicyBinding($run, $oldCandidate, $orchestrator);

        self::assertNotNull($run->candidate_invalidated_at);
        self::assertSame($oldCandidate->treeOid, $run->candidate_tree_sha);
        self::assertSame(GateState::OPEN, RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->firstOrFail()->state);

        $newCandidate = new PublishCandidate(str_repeat('c', 64), str_repeat('d', 64), $run->run_base_sha);
        $gate = $orchestrator->authorizeCandidateGateEvidence(
            $run, 'MG-01', $approver->id, 'intervention:tc10-new', $newCandidate, $run->version + 1,
        );

        self::assertSame(GateState::CLOSED, $gate->state);
        self::assertSame([], $orchestrator->invalidateStaleCandidateGateEvidence($run, $newCandidate));

        $run = $orchestrator->bindCandidate($run, $run->version, $newCandidate);

        self::assertNull($run->candidate_invalidated_at);
        self::assertSame($newCandidate->treeOid, $run->candidate_tree_sha);
        self::assertSame($newCandidate->diffHash, $run->candidate_diff_hash);
        self::assertSame(GateState::CLOSED, $gate->fresh()->state);
    }

    /** @return iterable<string, array{string}> */
    public static function candidateInvalidationCauseProvider(): iterable
    {
        yield 'ticket contract' => ['ticket'];
        yield 'prompt snapshot' => ['prompt'];
        yield 'security policy' => ['security'];
        yield 'checkpoint' => ['checkpoint'];
    }

    private function changeSecurityPolicyBinding(
        Run $run,
        PublishCandidate $candidate,
        RunOrchestrator $orchestrator,
    ): Run {
        DB::table('runs')->where('id', $run->id)->update([
            'security_policy_hash' => str_repeat('f', 64),
            'candidate_invalidated_at' => now(),
            'version' => $run->version + 1,
        ]);
        $changed = $run->fresh();
        $orchestrator->invalidateStaleCandidateGateEvidence($changed, $candidate);

        return $changed->fresh();
    }

    private function authorization(User $actor, string $runId, string $requestId, int $runVersion): InterventionAuthorization
    {
        $session = new Store('candidate-gate', new ArraySessionHandler(120));
        $session->setId('candidate-gate-'.$actor->id.'-'.bin2hex(random_bytes(4)));
        $session->start();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);

        return InterventionAuthorization::consumeFresh(
            $proof,
            $actor,
            $guard,
            HumanRequestAnswerController::STEP_UP_ACTION,
            [$runId, $requestId, $runVersion, GateEvidenceHumanRequestBinding::EFFECT],
        );
    }

    private function gateTicket(): string
    {
        return <<<'MARKDOWN'
            ---
            schema: ai6.ticket.v1
            id: AI6-027-GATE
            title: "Gate-Test"
            status: ready
            depends_on: []
            ---

            ## Goal

            Gatebindung prüfen.

            ## Manual and External Gates

            - **MG-01** Menschliche Prüfung.
            MARKDOWN;
    }
}
