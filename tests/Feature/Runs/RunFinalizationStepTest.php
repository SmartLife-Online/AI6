<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\SecurityReviewerProfileResolver;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Jobs\SendHumanRequestNotification;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\HumanLoop\SecurityGateHumanRequestBinding;
use App\AI6\HumanLoop\SecurityGateService;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\SecurityReviewEvidence;
use App\AI6\Reviews\SecurityReviewStep;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunFinalizationStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RunFinalizationStepTest extends TicketUiTestCase
{
    use BuildsCheckFixture, BuildsReviewRoundFixture {
        BuildsReviewRoundFixture::approvalSelection insteadof BuildsCheckFixture;
    }
    use BuildsFixLoopFixture;

    public function test_a_completed_fix_round_plans_and_executes_exactly_one_agent_free_finalization(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC01');
        $run = $this->withoutBoundChecks($prepared['run']);
        $identifier = (string) $run->project()->value('project_identifier');

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $adapter = $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $this->seedBeforeReviewCheck($run->fresh());
        $reviewEvidenceBefore = [
            'results' => ReviewResult::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'findings' => Finding::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'dispositions' => FindingDisposition::query()->whereIn(
                'finding_id', Finding::query()->where('run_id', $run->id)->select('id'),
            )->orderBy('id')->pluck('id')->all(),
            'checkpoint' => $run->only(['checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash']),
        ];

        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        self::assertSame(1, $finalize->step_number);
        self::assertSame(RunPhase::FINALIZE, $run->fresh()->phase);
        $turnsBefore = $adapter->turnCount;

        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );

        $finished = $run->fresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);
        self::assertNotNull($finished->candidate_tree_sha);
        self::assertNotNull($finished->candidate_diff_hash);
        self::assertSame($finished->run_base_sha, $finished->candidate_base_sha);
        self::assertSame($turnsBefore, $adapter->turnCount);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->count());
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::REVIEW->value)->where('step_number', 3)->exists());
        self::assertSame($reviewEvidenceBefore, [
            'results' => ReviewResult::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'findings' => Finding::query()->where('run_id', $run->id)->orderBy('id')->pluck('id')->all(),
            'dispositions' => FindingDisposition::query()->whereIn(
                'finding_id', Finding::query()->where('run_id', $run->id)->select('id'),
            )->orderBy('id')->pluck('id')->all(),
            'checkpoint' => $finished->only(['checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash']),
        ]);
    }

    public function test_bound_candidate_receives_a_fresh_clear_security_review_before_publish(): void
    {
        foreach ([
            'candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha',
            'candidate_ticket_contract_sha256', 'candidate_scope_hash',
            'candidate_prompt_snapshot_hash', 'candidate_instruction_snapshot_hash',
            'candidate_agent_profile_id', 'candidate_runtime_profile_hash',
            'candidate_security_policy_hash',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('review_results', $column));
        }
        $trigger = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'review_results_insert_guard'");
        self::assertIsObject($trigger);
        self::assertIsString($trigger->sql);
        self::assertStringContainsString("NEW.role = 'security_review'", $trigger->sql);
        self::assertStringContainsString("'clear', 'security_findings', 'needs_human', 'inconclusive'", $trigger->sql);
        self::assertStringContainsString('NEW.candidate_prompt_snapshot_hash <> NEW.slot_prompt_hash', $trigger->sql);
        self::assertStringContainsString('NEW.candidate_instruction_snapshot_hash <> NEW.slot_instruction_hash', $trigger->sql);
        self::assertStringContainsString('NEW.candidate_runtime_profile_hash <> NEW.slot_runtime_profile_hash', $trigger->sql);

        Queue::fake();
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-028-TC07');
        $run = $this->withoutBoundChecks($prepared['run']);
        $identifier = (string) $run->project()->value('project_identifier');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $adapter = $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $this->seedBeforeReviewCheck($run->fresh());
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);
        $this->bindSecurityManagedRepository($run->fresh(), $prepared['worktree']);

        $security = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $this->app->forgetInstance(SecurityReviewStep::class);
        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        $request = HumanRequest::query()->where('run_id', $run->id)->where('kind', 'security_gate')->first();
        self::assertSame(
            ExecutionJobState::SUCCEEDED,
            $security->fresh()->state,
            json_encode([
                'message' => $request instanceof HumanRequest ? $request->message : (string) $security->fresh()->failure_code,
                'security_slots' => $run->agents()->where('role', 'security_review')->count(),
                'turns' => $adapter->turnCount,
                'security_results' => ReviewResult::query()->where('run_id', $run->id)->where('role', 'security_review')->count(),
            ], JSON_THROW_ON_ERROR),
        );
        $result = ReviewResult::query()->where('run_id', $run->id)->where('role', 'security_review')->sole();
        self::assertSame('clear', $result->result_status);
        self::assertSame($run->fresh()->candidate_tree_sha, $result->candidate_tree_sha);
        self::assertSame($run->fresh()->candidate_diff_hash, $result->candidate_diff_hash);
        self::assertSame($run->fresh()->candidate_base_sha, $result->candidate_base_sha);
        self::assertNotEmpty($result->session_id);
        self::assertSame('denied', $adapter->lastAccessProbes['write:existing'] ?? null);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('denied', $adapter->lastAccessProbes['write:new'] ?? null);
        }
        self::assertSame('missing', $adapter->lastAccessProbes['workspace:.git'] ?? null);

        $evidence = $this->app->make(SecurityReviewEvidence::class);
        $current = $run->fresh();
        self::assertSame($result->id, $evidence->validClear($current)?->id);
        $originalBindings = $result->only([
            'candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha',
            'candidate_instruction_snapshot_hash', 'session_id',
        ]);
        foreach (['candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha'] as $field) {
            $drifted = clone $current;
            $drifted->setAttribute($field, str_repeat($field === 'candidate_tree_sha' ? 'a' : ($field === 'candidate_diff_hash' ? 'b' : 'c'), 64));
            self::assertNull($evidence->validClear($drifted), $field.' drift retained a clear result');
        }
        $instructionDrift = clone $current;
        $instructionSnapshot = $instructionDrift->instruction_snapshot;
        $provider = $instructionDrift->agent_profile_snapshot['security_reviewer']['provider_profile'];
        $instructionSnapshot[$provider]['instruction_snapshot_hash'] = str_repeat('d', 64);
        $instructionDrift->setAttribute('instruction_snapshot', $instructionSnapshot);
        self::assertNull($evidence->validClear($instructionDrift));
        $runtimeDrift = clone $current;
        $runtimeSnapshot = $runtimeDrift->runtime_profile_snapshot;
        $runtimeId = $runtimeDrift->agent_profile_snapshot['security_reviewer']['runtime_profile_id'];
        $runtimeSnapshot[$runtimeId]['hash'] = str_repeat('e', 64);
        $runtimeDrift->setAttribute('runtime_profile_snapshot', $runtimeSnapshot);
        self::assertNull($evidence->validClear($runtimeDrift));
        $promptDrift = clone $current;
        $promptSnapshot = $promptDrift->prompt_snapshot;
        $promptSnapshot['security_review_prompt_binding']['template_sha256'] = str_repeat('f', 64);
        $promptDrift->setAttribute('prompt_snapshot', $promptSnapshot);
        self::assertNull($evidence->validClear($promptDrift));
        self::assertSame($originalBindings, $result->fresh()->only(array_keys($originalBindings)));

        $oldSlot = $run->agents()->where('role', 'security_review')->where('is_active', true)->sole();
        $newSlot = $this->app->make(RunOrchestrator::class)->startSecurityReviewSession(
            $current,
            $this->app->make(SecurityReviewerProfileResolver::class)->resolve(),
            (string) Str::uuid(),
        );
        self::assertNotSame($oldSlot->session_id, $newSlot->session_id);
        self::assertFalse((bool) $oldSlot->fresh()->is_active);
        self::assertTrue((bool) $newSlot->is_active);
    }

    /** @return array<string, array{AgentScenario, bool, bool, string}> */
    public static function blockingSecurityScenarios(): array
    {
        return [
            'security findings' => [AgentScenario::SECURITY_FINDINGS, true, true, 'FINDINGS'],
            'needs human' => [AgentScenario::HUMAN_REQUEST, true, false, 'HUMAN'],
            'inconclusive' => [AgentScenario::SECURITY_INCONCLUSIVE, true, false, 'INCONCLUSIVE'],
            'invalid schema' => [AgentScenario::INVALID_JSON, false, false, 'INVALID'],
            'provider failure status' => [AgentScenario::PROVIDER_ERROR, false, false, 'PROVIDER'],
        ];
    }

    #[DataProvider('blockingSecurityScenarios')]
    public function test_every_non_clear_security_outcome_parks_at_the_security_gate(
        AgentScenario $scenario,
        bool $storesResult,
        bool $storesCriticalFinding,
        string $ticketSuffix,
    ): void {
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC07-'.$ticketSuffix);
        $this->bindSecurityManagedRepository($prepared['run'], $prepared['worktree']);
        $adapter = new FakeAgentAdapter($scenario);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->forgetInstance(SecurityReviewStep::class);
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();

        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        $run = $prepared['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(RunPhase::SECURITY_REVIEW, $run->phase);
        self::assertSame(WaitReason::SECURITY_GATE, $run->wait_reason);
        self::assertSame(ExecutionJobState::WAITING, $security->fresh()->state);
        self::assertSame((int) $storesResult, ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'security_review')->count());
        self::assertSame((int) $storesCriticalFinding, Finding::query()->where('run_id', $run->id)
            ->where('severity', 'critical')->count());
        self::assertSame(1, HumanRequest::query()->where('run_id', $run->id)
            ->where('kind', 'security_gate')->where('resolution_state', 'open')->count());
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)
            ->where('state', ExecutionJobState::SUCCEEDED->value)->exists());
        $request = HumanRequest::query()->where('run_id', $run->id)
            ->where('kind', 'security_gate')->sole();
        $securityProfile = $run->agent_profile_snapshot['security_reviewer'] ?? null;
        self::assertIsArray($securityProfile);
        self::assertIsString($profileId = $securityProfile['profile_id'] ?? null);
        self::assertIsString($provider = $securityProfile['provider_profile'] ?? null);
        $instruction = $run->instruction_snapshot[$provider] ?? null;
        self::assertIsArray($instruction);
        self::assertIsString($instructionHash = $instruction['instruction_snapshot_hash'] ?? null);
        $sameRequest = $this->app->make(HumanRequestService::class)->openSecurityGateRequest(
            $run,
            $security,
            $profileId,
            $instructionHash,
            'Erneuter idempotenter Zustellversuch.',
            $storesCriticalFinding,
        );
        self::assertSame($request->id, $sameRequest->id);
        Queue::assertPushed(SendHumanRequestNotification::class, 1);

        if ($scenario === AgentScenario::SECURITY_FINDINGS) {
            self::assertContains(SecurityGateHumanRequestBinding::EFFECT, $request->allowed_effects);
            $administrator = $this->memberWithRole($run, ProjectRole::ADMIN);
            $this->actingAs($administrator)->post(route('projects.human-requests.answer', [
                $run->project()->firstOrFail(), $request->id,
            ]), [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => SecurityGateHumanRequestBinding::EFFECT,
                'reason' => 'Overrideversuch ohne frischen Step-up.',
            ])->assertSessionHasErrors('chosen_effect');
            $missingStepUpAudit = Intervention::query()
                ->where('chosen_effect', 'security_override_rejected')
                ->where('step_up_verified', false)
                ->sole();
            self::assertNull($missingStepUpAudit->step_up_proof_hash);
            self::assertSame('step_up_required', $missingStepUpAudit->reason);
            self::assertSame(RunState::WAITING, $run->fresh()->state);
            self::assertSame('open', $request->fresh()->resolution_state->value);

            try {
                $this->overrideSecurityGate($request, $administrator, str_repeat('e', 64));
                self::fail('A security override for a foreign candidate was accepted.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame('stale_checkpoint', $rejected->reason);
            }
            self::assertSame(RunState::WAITING, $run->fresh()->state);
            self::assertSame('open', $request->fresh()->resolution_state->value);

            $approver = $this->memberWithRole($run, ProjectRole::APPROVER);
            try {
                $this->overrideSecurityGate($request, $approver);
                self::fail('A non-administrator security override was accepted.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame('administrator_role_required', $rejected->reason);
            }
            self::assertSame(RunState::WAITING, $run->fresh()->state);
            self::assertTrue(Intervention::query()->where('chosen_effect', 'security_override_rejected')->exists());

            try {
                $this->app->make(HumanRequestService::class)->answer(
                    $request->fresh(),
                    $administrator,
                    $request->bound_run_version,
                    $request->bound_ticket_contract,
                    $request->bound_checkpoint,
                    $request->bound_scope,
                    $request->bound_agent_slot,
                    $request->bound_requested_effect,
                    SecurityGateHumanRequestBinding::EFFECT,
                    $this->authorization($administrator, $request, SecurityGateHumanRequestBinding::EFFECT),
                );
                self::fail('The specialized security override was accepted through the generic answer service.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame('specialized_effect_required', $rejected->reason);
            }

            $this->postSecurityAnswer($request->fresh(), $administrator, SecurityGateHumanRequestBinding::EFFECT)
                ->assertSessionHasNoErrors()
                ->assertRedirect();
            $intervention = Intervention::query()->where('human_request_id', $request->id)
                ->where('chosen_effect', SecurityGateHumanRequestBinding::EFFECT)->sole();
            self::assertSame(SecurityGateHumanRequestBinding::EFFECT, $intervention->chosen_effect);
            self::assertTrue((bool) $intervention->step_up_verified);
            self::assertSame(RunState::RUNNING, $run->fresh()->state);
            self::assertSame(ExecutionJobState::PLANNED, $security->fresh()->state);
            self::assertSame($intervention->id, $this->app->make(SecurityReviewEvidence::class)
                ->validOverride($run->fresh())?->id);
            self::assertSame('accepted_risk', FindingDisposition::query()
                ->whereIn('finding_id', Finding::query()->where('run_id', $run->id)->select('id'))
                ->where('type', 'accepted_risk')->sole()->type->value);

            (new ExecuteRunStep($security->id))->handle(
                $this->app->make(RunOrchestrator::class),
                securityReview: $this->app->make(SecurityReviewStep::class),
            );
            self::assertSame(ExecutionJobState::SUCCEEDED, $security->fresh()->state, (string) $security->fresh()->failure_code);
            self::assertSame(RunPhase::PUBLISH, $run->fresh()->phase);
            self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
                ->where('step_type', ExecutionStepType::FIX->value)->where('step_number', 2)->exists());
        }
    }

    public function test_a_bound_retry_rejects_stale_inputs_then_uses_a_fresh_session_and_clear(): void
    {
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC10-RETRY');
        $this->bindSecurityManagedRepository($prepared['run'], $prepared['worktree']);
        $this->app->instance(FakeAgentAdapter::class, new FakeAgentAdapter(AgentScenario::SECURITY_INCONCLUSIVE));
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $this->app->forgetInstance(SecurityReviewStep::class);
        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'security_gate')->where('resolution_state', 'open')->sole();
        self::assertContains(SecurityGateHumanRequestBinding::RETRY_EFFECT, $request->allowed_effects);
        $actor = $this->approver($prepared['run']);

        foreach ([
            'stale_run_version' => ['version' => $request->bound_run_version + 1, 'checkpoint' => $request->bound_checkpoint],
            'stale_checkpoint' => ['version' => $request->bound_run_version, 'checkpoint' => str_repeat('f', 64)],
        ] as $expected => $input) {
            try {
                $this->app->make(HumanRequestService::class)->answer(
                    $request,
                    $actor,
                    $input['version'],
                    $request->bound_ticket_contract,
                    $input['checkpoint'],
                    $request->bound_scope,
                    $request->bound_agent_slot,
                    $request->bound_requested_effect,
                    SecurityGateHumanRequestBinding::RETRY_EFFECT,
                );
                self::fail('A stale security retry was accepted.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame($expected, $rejected->reason);
            }
            self::assertSame('open', $request->fresh()->resolution_state->value);
            self::assertSame(RunState::WAITING, $prepared['run']->fresh()->state);
        }

        $this->postSecurityAnswer($request, $actor, SecurityGateHumanRequestBinding::RETRY_EFFECT)
            ->assertRedirect();
        $firstSession = $prepared['run']->agents()->where('role', 'security_review')->sole()->session_id;
        $this->app->instance(FakeAgentAdapter::class, new FakeAgentAdapter(AgentScenario::SUCCESS));
        $this->app->forgetInstance(SecurityReviewStep::class);
        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        self::assertSame(ExecutionJobState::SUCCEEDED, $security->fresh()->state, (string) $security->fresh()->failure_code);
        self::assertSame(RunPhase::PUBLISH, $prepared['run']->fresh()->phase);
        $active = $prepared['run']->agents()->where('role', 'security_review')->where('is_active', true)->sole();
        self::assertNotSame($firstSession, $active->session_id);
        self::assertSame(2, $prepared['run']->agents()->where('role', 'security_review')->count());
    }

    public function test_single_review_round_security_override_reaches_publish_without_verify_or_fix_reentry(): void
    {
        $prepared = $this->prepareSingleRoundSecurityCandidate('AI6-028-AC10-FENCE');
        $this->bindSecurityManagedRepository($prepared['run'], $prepared['worktree']);
        $this->app->instance(FakeAgentAdapter::class, new FakeAgentAdapter(AgentScenario::SECURITY_FINDINGS));
        $this->app->forgetInstance(SecurityReviewStep::class);
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();

        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'security_gate')->where('resolution_state', 'open')->sole();
        $administrator = $this->memberWithRole($prepared['run'], ProjectRole::ADMIN);
        $this->postSecurityAnswer($request, $administrator, SecurityGateHumanRequestBinding::EFFECT)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        $run = $prepared['run']->fresh();
        self::assertSame(ExecutionJobState::SUCCEEDED, $security->fresh()->state, (string) $security->fresh()->failure_code);
        self::assertSame(RunPhase::PUBLISH, $run->phase);
        self::assertNull($this->app->make(RunOrchestrator::class)->planNextStep($run));
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->whereIn('step_type', [ExecutionStepType::VERIFY->value, ExecutionStepType::FIX->value])->exists());
    }

    public function test_security_runtime_limit_parks_under_security_gate_before_resource_limit(): void
    {
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC15-RUNTIME');
        $this->bindSecurityManagedRepository($prepared['run'], $prepared['worktree']);
        $snapshot = $prepared['run']->agent_profile_snapshot;
        $snapshot['limits']['max_run_minutes'] = 1;
        DB::table('runs')->where('id', $prepared['run']->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(2),
            'version' => DB::raw('version + 1'),
        ]);
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();

        (new ExecuteRunStep($security->id))->handle($this->app->make(RunOrchestrator::class));

        self::assertSame(ExecutionJobState::WAITING, $security->fresh()->state);
        self::assertSame(WaitReason::SECURITY_GATE, $prepared['run']->fresh()->wait_reason);
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'security_gate')->where('resolution_state', 'open')->sole();
        self::assertStringContainsString('Laufzeitlimit', $request->message);
        self::assertStringContainsString('security_runtime_limit', $request->why_needed);
    }

    public function test_missing_security_policy_binding_parks_fail_closed_instead_of_throwing(): void
    {
        $missing = $this->prepareSecurityCandidate('AI6-028-POLICY-MISSING');
        $job = ExecutionJob::query()->where('run_id', $missing['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $owner = str_repeat('a', 32);
        $claimed = $this->app->make(RunOrchestrator::class)->claimStep($job, $owner);
        self::assertNotNull($claimed);
        $unbound = clone $missing['run']->fresh();
        $unbound->setAttribute('security_policy_hash', null);
        $this->app->make(SecurityReviewStep::class)->execute($claimed, $unbound, $owner);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);
        self::assertSame(WaitReason::SECURITY_GATE, $missing['run']->fresh()->wait_reason);
    }

    public function test_security_gate_soft_cancel_runs_through_the_real_answer_route(): void
    {
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC11-CANCEL');
        $this->bindSecurityManagedRepository($prepared['run'], $prepared['worktree']);
        $this->app->instance(FakeAgentAdapter::class, new FakeAgentAdapter(AgentScenario::SECURITY_INCONCLUSIVE));
        $this->app->forgetInstance(SecurityReviewStep::class);
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'security_gate')->where('resolution_state', 'open')->sole();
        $actor = $this->memberWithRole($prepared['run'], ProjectRole::OPERATOR);

        $this->postSecurityAnswer($request, $actor, 'soft_cancel')->assertRedirect();

        self::assertSame('cancelled', $request->fresh()->resolution_state->value);
        self::assertNotNull($prepared['run']->fresh()->pending_status_operation_id);
    }

    public function test_security_workspace_start_failure_is_fail_closed_without_a_result(): void
    {
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC08');
        $security = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $this->app->forgetInstance(SecurityReviewStep::class);

        (new ExecuteRunStep($security->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        $run = $prepared['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertSame(RunPhase::SECURITY_REVIEW, $run->phase);
        self::assertSame(WaitReason::SECURITY_GATE, $run->wait_reason);
        self::assertSame(ExecutionJobState::WAITING, $security->fresh()->state);
        self::assertFalse(ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'security_review')->exists());
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)
            ->where('state', ExecutionJobState::SUCCEEDED->value)->exists());
    }

    public function test_confirmed_policy_reduction_records_a_skip_without_a_passed_result(): void
    {
        $security = config('ai6.security');
        $security['profile'] = 'custom';
        $security['acknowledge_reduced_mode'] = true;
        $security['measures'][SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value] = false;
        config(['ai6.security' => $security]);
        $this->app->instance(SecurityPolicy::class, (new SecurityPolicyFactory)->fromConfiguredValues());
        $prepared = $this->prepareSecurityCandidate('AI6-028-TC14');
        $job = ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::SECURITY_REVIEW->value)->sole();
        $adapter = $this->app->make(FakeAgentAdapter::class);
        $turns = $adapter->turnCount;
        $this->app->forgetInstance(SecurityReviewStep::class);

        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            securityReview: $this->app->make(SecurityReviewStep::class),
        );

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->fresh()->state);
        self::assertSame(RunPhase::PUBLISH, $prepared['run']->fresh()->phase);
        self::assertSame($turns, $adapter->turnCount);
        self::assertFalse(ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'security_review')->exists());
        $event = RunEvent::query()->where('run_id', $prepared['run']->id)
            ->where('event_type', 'security_review_skipped')->sole();
        self::assertStringContainsString('übersprungen', $event->redacted_payload);
        self::assertStringContainsString('kein bestandener Sicherheitsnachweis', $event->redacted_payload);
    }

    public function test_an_effectively_blocking_finding_does_not_plan_finalization(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC01-BLOCKED');
        $run = $this->withoutBoundChecks($prepared['run']);
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertFalse(ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->exists());
    }

    public function test_control_base_drift_parks_finalization_without_rewriting_run_branch_or_binding_candidate(): void
    {
        $this->configureChecks();
        $prepared = $this->preparedReviewRun('AI6-027-TC14');
        $run = $this->withoutBoundChecks($prepared['run']);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        $branchBefore = $this->gitOutput(['rev-parse', (string) $run->run_branch], $prepared['worktree']);
        $run->project()->update(['control_oid' => str_repeat('f', 64)]);

        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );

        $parked = $run->fresh();
        self::assertSame(RunState::WAITING, $parked->state, (string) $finalize->fresh()->failure_code);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $parked->wait_reason);
        self::assertSame(ExecutionJobState::WAITING, $finalize->fresh()->state);
        self::assertSame($branchBefore, $this->gitOutput(['rev-parse', (string) $run->run_branch], $prepared['worktree']));
        self::assertNull($parked->candidate_tree_sha);
        self::assertNull($parked->candidate_diff_hash);
        self::assertTrue(HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->exists());
    }

    private function withoutBoundChecks(Run $run): Run
    {
        self::assertSame(['probe-ok'], $run->config_snapshot['values']['checks']['before_review'] ?? null);
        self::assertSame(['probe-final'], $run->config_snapshot['values']['checks']['final'] ?? null);

        return $run->fresh();
    }

    private function configureChecks(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['--version'])]);
        config(['ai6.checks.profiles.probe-final' => $this->probeProfile(['--version'], phases: ['final'])]);
    }

    private function seedBeforeReviewCheck(Run $run): Run
    {
        $tree = $this->app->make(CheckRunner::class)->currentTreeBinding($run);
        CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
            'evidence_epoch' => $run->evidence_epoch, 'profile' => 'probe-ok',
            'state' => CheckResultState::SUCCEEDED, 'reason' => null, 'exit_code' => 0,
            'duration_ms' => 1, 'redacted_output' => 'ok', 'tree_sha' => $tree, 'result_tree_sha' => $tree,
            'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
            'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, 'probe-ok', $tree),
        ]);

        return $run;
    }

    private function bindSecurityManagedRepository(Run $run, string $worktree): void
    {
        $managed = $this->implementationTemp('security-managed');
        $identifier = (string) $run->project()->value('project_identifier');
        $repository = $managed.'/projects/'.$identifier.'/repository';
        self::assertTrue(mkdir(dirname($repository), 0700, true));
        $common = $this->gitOutput(['rev-parse', '--path-format=absolute', '--git-common-dir'], $worktree);
        $source = dirname($common);
        self::assertTrue($this->app->make(Filesystem::class)->copyDirectory($source, $repository));
        $configuration = new ControlOperationConfiguration(
            $managed,
            $managed.'/keys',
            PHP_BINARY,
            PHP_BINARY,
            120,
            30,
            30,
            3,
            $managed.'/known-hosts',
            ['refs/heads/main'],
            300,
            8,
        );
        $this->app->instance(ControlOperationConfiguration::class, $configuration);
        $this->app->forgetInstance(ManagedProjectPath::class);
    }

    /** @return array{run: Run, worktree: string} */
    private function prepareSecurityCandidate(string $ticketId): array
    {
        Queue::fake();
        $this->configureChecks();
        $prepared = $this->preparedReviewRun($ticketId);
        $run = $this->withoutBoundChecks($prepared['run']);
        $identifier = (string) $run->project()->value('project_identifier');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $this->seedBeforeReviewCheck($run->fresh());
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);

        return ['run' => $run->fresh(), 'worktree' => $prepared['worktree']];
    }

    /** @return array{run: Run, worktree: string} */
    private function prepareSingleRoundSecurityCandidate(string $ticketId): array
    {
        Queue::fake();
        $this->configureChecks();
        $prepared = $this->preparedReviewRun(
            $ticketId,
            implementationScenario: AgentScenario::SUCCESS,
        );
        $run = $this->withoutBoundChecks($prepared['run']);
        $this->reviewAdapter([]);
        $review = $this->executeReviewRound($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $review->state, (string) $review->failure_code);
        $run = $this->seedBeforeReviewCheck($run->fresh());
        $finalize = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FINALIZE->value)->sole();
        (new ExecuteRunStep($finalize->id))->handle(
            $this->app->make(RunOrchestrator::class),
            finalization: $this->app->make(RunFinalizationStep::class),
        );
        self::assertSame(ExecutionJobState::SUCCEEDED, $finalize->fresh()->state, (string) $finalize->fresh()->failure_code);

        return ['run' => $run->fresh(), 'worktree' => $prepared['worktree']];
    }

    private function memberWithRole(Run $run, ProjectRole $role): User
    {
        return ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', $role->value)->firstOrFail()->user()->firstOrFail();
    }

    private function overrideSecurityGate(HumanRequest $request, User $actor, ?string $checkpoint = null): Intervention
    {
        $proof = Request::create('/human-request', 'POST');
        $this->startSession();
        $proof->setLaravelSession($this->app->make('session')->driver());
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);
        $authorization = InterventionAuthorization::consumeFresh(
            $proof,
            $actor,
            $guard,
            HumanRequestAnswerController::STEP_UP_ACTION,
            [$request->run_id, $request->id, $request->bound_run_version, SecurityGateHumanRequestBinding::EFFECT],
        );

        return $this->app->make(SecurityGateService::class)->override(
            $request,
            $actor,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $checkpoint ?? $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            $authorization,
            'Kritischen Befund bewusst und gebunden übersteuern.',
        );
    }

    /** @return TestResponse<Response> */
    private function postSecurityAnswer(HumanRequest $request, User $actor, string $effect): TestResponse
    {
        $request = $request->fresh();
        $this->actingAs($actor);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $proof,
            $actor,
            HumanRequestAnswerController::STEP_UP_ACTION,
        );
        $session->save();

        return $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [
                $request->project()->firstOrFail(), $request->id,
            ]), [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => $effect,
                'reason' => 'Gebundene Security-Entscheidung über die reale Route.',
            ]);
    }
}
