<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\ReportOnlyHumanRequestBinding;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\FindingCategory;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\GateKind;
use App\AI6\Runs\GateState;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\ReportOnlyCompletionService;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\ReviewOnlyCompletionPredicate;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ReviewOnlyRunContractTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_review_only_migration_round_trips_published_guards_and_complete_indexes(): void
    {
        $migration = require database_path('migrations/2026_08_25_000000_add_review_only_run_contract.php');
        $mutationGuardNames = ['ticket_mutations_insert_guard', 'ticket_mutations_update_guard'];
        $extendedMutationOperations = "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'";
        $originalMutationOperations = "'block', 'cancel', 'return_to_todo', 'complete_review'";
        $extendedWaitReasons = "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict','manual_report','status_sync'";
        $originalWaitReasons = "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict'";

        $extendedGuards = [];
        $originalGuards = [];
        foreach ($mutationGuardNames as $name) {
            $extendedGuards[$name] = $this->sqliteTrigger($name);
            self::assertStringContainsString($extendedMutationOperations, $extendedGuards[$name]);
            $originalGuards[$name] = str_replace(
                $extendedMutationOperations,
                $originalMutationOperations,
                $extendedGuards[$name],
                $replacementCount,
            );
            self::assertSame(1, $replacementCount);
        }

        $extendedGuards['interventions_audit_insert_guard'] = $this->sqliteTrigger('interventions_audit_insert_guard');
        self::assertStringContainsString($extendedWaitReasons, $extendedGuards['interventions_audit_insert_guard']);
        $originalGuards['interventions_audit_insert_guard'] = str_replace(
            $extendedWaitReasons,
            $originalWaitReasons,
            $extendedGuards['interventions_audit_insert_guard'],
            $replacementCount,
        );
        self::assertSame(1, $replacementCount);

        $runIndexes = $this->sqliteIndexes('runs');
        $approvalIndexes = $this->sqliteIndexes('ticket_approvals');

        $migration->down();
        try {
            foreach ($originalGuards as $name => $sql) {
                self::assertSame($sql, $this->sqliteTrigger($name));
            }
            self::assertSame($runIndexes, $this->sqliteIndexes('runs'));
            self::assertSame($approvalIndexes, $this->sqliteIndexes('ticket_approvals'));
        } finally {
            $migration->up();
        }

        foreach ($extendedGuards as $name => $sql) {
            self::assertSame($sql, $this->sqliteTrigger($name));
        }
        self::assertSame($runIndexes, $this->sqliteIndexes('runs'));
        self::assertSame($approvalIndexes, $this->sqliteIndexes('ticket_approvals'));
    }

    public function test_review_only_claim_reuses_run_start_and_creates_no_implementation_step(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-1');
        $operation = $this->queueStart($fixture);
        $parameters = json_decode($operation->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('review_only', $parameters['run_type'] ?? null);

        $run = $this->finalizeQueuedClaim($fixture, $operation);
        self::assertSame(RunType::REVIEW_ONLY, $run->run_type);
        self::assertSame('checkpoint:server-bound', $run->review_subject_reference);
        self::assertSame(ReviewOnlyCompletionMode::MANUAL, $run->completion_mode);
        self::assertSame(0, DB::table('execution_jobs')->where('run_id', $run->id)->count());
        self::assertNull($run->run_branch);
        self::assertNull($run->worktree_path);
    }

    public function test_review_only_approval_and_run_bindings_are_immutable(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-2');
        $run = $this->finalizedRun($fixture);

        try {
            DB::table('ticket_approvals')->where('id', $fixture['approval']->id)->update(['run_type' => 'implementation']);
            self::fail('The approval run type was mutable.');
        } catch (QueryException) {
            self::assertSame(RunType::REVIEW_ONLY, $fixture['approval']->fresh()->run_type);
        }
        try {
            DB::table('runs')->where('id', $run->id)->update(['review_subject_reference' => 'checkpoint:changed']);
            self::fail('The run review subject was mutable.');
        } catch (QueryException) {
            self::assertSame('checkpoint:server-bound', $run->fresh()->review_subject_reference);
        }
    }

    public function test_review_subject_reference_rejects_characters_outside_the_server_allowlist(): void
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model',
                'effort' => 'high', 'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            null,
            'manual',
            RunType::REVIEW_ONLY,
            "checkpoint:server-bound\nforged",
            ReviewOnlyCompletionMode::MANUAL,
        );
    }

    public function test_manual_completion_binds_status_sync_before_any_lock_release(): void
    {
        Queue::fake();
        $attention = $this->createUser();
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-3', attentionUser: $attention);
        $run = $this->completionReadyRun($fixture);

        $request = $this->app->make(ReportOnlyCompletionService::class)->start($run->fresh());
        self::assertInstanceOf(HumanRequest::class, $request);
        self::assertSame(WaitReason::MANUAL_REPORT, $run->fresh()->wait_reason);

        $inProgress = str_replace('status: ready', 'status: in_progress', (string) DB::table('ticket_read_models')
            ->where('project_id', $run->project_id)->value('redacted_content'));
        DB::table('ticket_read_models')->where('project_id', $run->project_id)->update([
            'control_operation_id' => $run->status_operation_id,
            'control_commit' => $run->run_base_sha,
            'blob_sha' => hash('sha256', 'blob '.strlen($inProgress)."\0".$inProgress),
            'redacted_content' => $inProgress,
            'generated_at' => now(),
            'updated_at' => now(),
        ]);
        $syncing = $this->app->make(ReportOnlyCompletionService::class)->confirm(
            $request,
            $attention,
            $this->authorization($attention, $request, 'confirm_report'),
        );
        self::assertSame(WaitReason::STATUS_SYNC, $syncing->wait_reason);
        self::assertNotNull($syncing->pending_status_operation_id);
        self::assertSame('ready', TicketMutation::query()->findOrFail($syncing->pending_status_operation_id)->target_status);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
        self::assertSame(RunState::WAITING, $syncing->state);
    }

    public function test_completion_rejects_a_missing_current_control_read_model_with_a_named_reason(): void
    {
        Queue::fake();
        $attention = $this->createUser();
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-4', attentionUser: $attention);
        $run = $this->completionReadyRun($fixture);
        $request = $this->app->make(ReportOnlyCompletionService::class)->start($run);
        DB::table('ticket_read_models')->where('project_id', $run->project_id)->delete();

        try {
            $this->app->make(ReportOnlyCompletionService::class)->confirm(
                $request,
                $attention,
                $this->authorization($attention, $request, 'confirm_report'),
            );
            self::fail('The missing current-control read model was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('report_status_binding_missing', $rejected->reason);
        }
        self::assertSame(RunState::WAITING, $run->fresh()->state);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
    }

    public function test_manual_confirmation_rejects_a_changed_review_binding_without_partial_effect(): void
    {
        Queue::fake();
        $attention = $this->createUser();
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-STALE', attentionUser: $attention);
        $run = $this->completionReadyRun($fixture);
        $request = $this->app->make(ReportOnlyCompletionService::class)->start($run);
        self::assertInstanceOf(HumanRequest::class, $request);
        $changed = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run->fresh(),
            $run->fresh()->version,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
        );

        try {
            $this->app->make(ReportOnlyCompletionService::class)->confirm(
                $request,
                $attention,
                $this->authorization($attention, $request, 'confirm_report'),
            );
            self::fail('The stale report confirmation was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('stale_report_confirmation', $rejected->reason);
        }
        self::assertSame(RunState::WAITING, $changed->fresh()->state);
        self::assertSame(WaitReason::MANUAL_REPORT, $changed->fresh()->wait_reason);
        self::assertNull($changed->fresh()->pending_status_operation_id);
        self::assertSame('open', $request->fresh()->resolution_state->value);
        self::assertSame($run->id, $fixture['project']->fresh()->active_run_id);
    }

    public function test_completion_predicate_blocks_a_live_status_sync_wait(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-5');
        $run = $this->completionReadyRun($fixture);
        $predicate = $this->app->make(ReviewOnlyCompletionPredicate::class);
        self::assertTrue($predicate->decide($run)->ready());

        DB::table('runs')->where('id', $run->id)->update([
            'state' => RunState::WAITING->value,
            'wait_reason' => WaitReason::STATUS_SYNC->value,
            'version' => DB::raw('version + 1'),
        ]);
        self::assertContains('run_waiting:status_sync', $predicate->decide($run->fresh())->blockers);
    }

    public function test_completion_predicate_rejects_a_missing_required_slot_result(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-6');
        $run = $this->completionReadyRun($fixture, false);
        $blockers = $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run)->blockers;

        self::assertContains('review_round_missing', $blockers);
        self::assertTrue(collect($blockers)->contains(
            static fn (string $blocker): bool => str_starts_with($blocker, 'slot_result_missing:'),
        ));
    }

    public function test_completion_predicate_ignores_an_open_effective_must_fix_finding(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-MUST-FIX');
        $run = $this->completionReadyRun($fixture);
        $result = ReviewResult::query()->where('run_id', $run->id)->sole();
        Finding::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'review_result_id' => $result->id,
            'round_number' => 1, 'slot_id' => $result->slot_id, 'provider_profile' => $result->provider_profile,
            'model' => $result->model, 'effort' => $result->effort, 'prompt_profile' => $result->prompt_profile,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha, 'diff_hash' => $run->checkpoint_diff_hash,
            'local_id' => 'must-fix-1', 'severity' => FindingSeverity::HIGH,
            'original_disposition' => FindingOriginalDisposition::MUST_FIX,
            'category' => FindingCategory::CORRECTNESS, 'file' => 'app/Example.php', 'line' => 1,
            'title' => 'Wirksamer Befund', 'evidence' => 'Gebundene Evidenz.',
            'expected_result' => 'Der Befund bleibt Ergebnis.', 'criterion_refs' => [],
            'duplicate_group' => hash('sha256', 'review-only-must-fix'),
        ]);

        self::assertTrue($this->app->make(RunOrchestrator::class)->hasEffectiveBlockingFindings($run));
        self::assertTrue($this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run)->ready());
    }

    public function test_completion_predicate_blocks_an_open_human_request(): void
    {
        Queue::fake();
        $attention = $this->createUser();
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-REQUEST', attentionUser: $attention);
        $run = $this->completionReadyRun($fixture);
        $this->app->make(ReportOnlyCompletionService::class)->start($run);
        $blockers = $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run->fresh())->blockers;

        self::assertContains('human_request_open', $blockers);
        self::assertContains('run_waiting:manual_report', $blockers);
    }

    public function test_completion_predicate_blocks_a_limit_wait(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-LIMIT');
        $run = $this->completionReadyRun($fixture);
        DB::table('runs')->where('id', $run->id)->update([
            'state' => RunState::WAITING->value,
            'wait_reason' => WaitReason::RESOURCE_LIMIT->value,
            'version' => DB::raw('version + 1'),
        ]);

        self::assertContains(
            'run_waiting:resource_limit',
            $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run->fresh())->blockers,
        );
    }

    public function test_completion_predicate_blocks_an_open_gate(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-GATE');
        $run = $this->completionReadyRun($fixture);
        RunGate::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'gate_id' => 'MG-01',
            'kind' => GateKind::MANUAL, 'state' => GateState::OPEN,
            'blocks_candidate' => true, 'blocks_final_commit' => true, 'blocks_push' => true,
            'ticket_contract_sha256' => $fixture['approval']->ticket_contract_sha256,
        ]);

        self::assertContains(
            'gate_open',
            $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run)->blockers,
        );
    }

    public function test_completion_predicate_blocks_incomplete_acceptance_criterion_coverage(): void
    {
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-COVERAGE');
        $run = $this->completionReadyRun($fixture, withCoverage: false);

        $blockers = $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run)->blockers;
        self::assertTrue(collect($blockers)->contains(
            static fn (string $blocker): bool => str_starts_with($blocker, 'criterion_coverage_incomplete:'),
        ));
    }

    public function test_refresh_routing_uses_report_status_request_provenance_not_run_type(): void
    {
        $runId = (string) Str::uuid();
        $reportConflict = new HumanRequest;
        $reportConflict->forceFill([
            'run_id' => $runId,
            'bound_agent_slot' => 'system:report-only',
            'bound_step_key' => hash('sha256', $runId.':report-status-conflict'),
        ]);
        $cancellationConflict = new HumanRequest;
        $cancellationConflict->forceFill([
            'run_id' => $runId,
            'bound_agent_slot' => 'system:report-only',
            'bound_step_key' => hash('sha256', $runId.':report-only-completion'),
        ]);
        self::assertTrue(ReportOnlyHumanRequestBinding::matches($reportConflict, 'refresh_expected_oid'));
        self::assertFalse(ReportOnlyHumanRequestBinding::matches($cancellationConflict, 'refresh_expected_oid'));
    }

    public function test_panel_offers_the_report_status_conflict_decision_but_not_the_cancellation_one(): void
    {
        Queue::fake();
        $attention = $this->createUser();
        $fixture = $this->completedApproval('AI6-REVIEW-ONLY-PANEL', attentionUser: $attention);
        $run = $this->completionReadyRun($fixture);
        $this->app->make(RunOrchestrator::class)->parkOnGitConflict($run->fresh(), $run->fresh()->version);
        $reportConflict = $this->app->make(HumanRequestService::class)
            ->openReportStatusConflictRequest($run->fresh());

        $submitButton = '<button type="submit" name="chosen_effect" value="refresh_expected_oid">';
        $this->actingAs($attention)
            ->get(route('projects.human-requests.show', [$fixture['project'], $reportConflict->id]))
            ->assertOk()
            ->assertSee($submitButton, false)
            ->assertSee('TOTP-Code für Step-up')
            ->assertDontSee('muss die Statusentscheidung mit einem der folgenden Wege erneut autorisiert werden');

        // A role the report-only saga would reject is offered no control.
        DB::table('project_memberships')->where('project_id', $fixture['project']->id)
            ->where('user_id', $attention->getKey())->update(['role' => 'operator']);
        $this->actingAs($attention)
            ->get(route('projects.human-requests.show', [$fixture['project'], $reportConflict->id]))
            ->assertOk()
            ->assertDontSee($submitButton, false);
        DB::table('project_memberships')->where('project_id', $fixture['project']->id)
            ->where('user_id', $attention->getKey())->update(['role' => 'approver']);

        // The identically named cancellation conflict keeps its explanatory
        // text and stays unclickable: it resolves through a re-issued mode.
        DB::table('human_requests')->where('id', $reportConflict->id)->update([
            'bound_agent_slot' => 'implementation-slot',
            'bound_step_key' => hash('sha256', $run->id.':cancellation'),
        ]);

        $this->actingAs($attention)
            ->get(route('projects.human-requests.show', [$fixture['project'], $reportConflict->id]))
            ->assertOk()
            ->assertDontSee($submitButton, false)
            ->assertSee('muss die Statusentscheidung mit einem der folgenden Wege erneut autorisiert werden');
    }

    public function test_status_sync_registers_the_authorized_conflict_effect(): void
    {
        self::assertSame([
            'producer' => 'ReportOnlyCompletionService',
            'resolvers' => ['refresh_expected_oid'],
            'cancellable' => true,
        ], $this->app->make(WaitReasonRegistry::class)->registration(WaitReason::STATUS_SYNC));
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attentionUser?->getKey(),
            'manual',
            RunType::REVIEW_ONLY,
            'checkpoint:server-bound',
            ReviewOnlyCompletionMode::MANUAL,
        );
    }

    /** @param array{operator: User, project: mixed, approval: mixed} $fixture */
    private function finalizeQueuedClaim(array $fixture, ControlOperation $operation): Run
    {
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)
            ->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $target = str_repeat('c', 64);
        DB::table('projects')->where('id', $fixture['project']->id)->update([
            'control_oid' => $target,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'target_control_oid' => $target,
            'phase' => 'control_confirmed',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);

        return $this->app->make(RunOrchestrator::class)->finalizeClaim(
            $fixture['approval']->refresh(),
            $operation->refresh(),
            $attemptToken,
            (string) $operation->expected_control_commit,
            $target,
        );
    }

    private function authorization(User $actor, HumanRequest $request, string $effect): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('review-only-'.$actor->id.'-'.bin2hex(random_bytes(4)));
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
            [$request->run_id, $request->id, $request->bound_run_version, $effect],
        );
    }

    /**
     * @param  array{
     *     operator: User,
     *     project: Project,
     *     approval: TicketApproval
     * }  $fixture
     */
    private function completionReadyRun(array $fixture, bool $withResult = true, bool $withCoverage = true): Run
    {
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::PREPARE);
        $run = $orchestrator->advancePhase($run, $run->version, RunPhase::REVIEW);
        $run = $orchestrator->bindCheckpoint(
            $run,
            $run->version,
            str_repeat('6', 64),
            str_repeat('7', 64),
            str_repeat('8', 64),
        );
        $orchestrator->materializeReviewSlots($run);
        if ($withResult) {
            $this->completionReadyResult($run, $fixture['approval']->approval_snapshot_hash, $withCoverage);
        }

        return $run->fresh();
    }

    private function completionReadyResult(Run $run, string $approvalSnapshotHash, bool $withCoverage): ReviewResult
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $slot = $run->agents()->where('role', 'quality_review')->firstOrFail();
        if ($slot->session_id === null) {
            $slot = $orchestrator->bindReviewSession($run, $slot->slot_id, (string) Str::uuid());
        }
        $artifact = RunArtifact::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'kind' => 'provider_raw',
            'redacted_metadata' => [], 'digest' => str_repeat('9', 64), 'size_bytes' => 2,
            'sequence' => RunArtifact::query()->where('run_id', $run->id)->count() + 1,
            'storage_reference' => 'test://review-only-result/'.Str::uuid(), 'expires_at' => now()->addDay(),
        ]);

        $result = ReviewResult::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'round_number' => 1,
            'slot_id' => $slot->slot_id, 'attempt' => 1, 'role' => 'quality_review',
            'provider_profile' => $slot->provider_profile, 'model' => $slot->model,
            'effort' => $slot->effort, 'prompt_profile' => $slot->prompt_profile,
            'session_id' => $slot->session_id, 'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha, 'diff_hash' => $run->checkpoint_diff_hash,
            'approval_config_hash' => $run->config_hash, 'approval_scope_hash' => $run->scope_hash,
            'approval_prompt_hash' => $run->prompt_hash, 'approval_instruction_hash' => $run->instruction_hash,
            'approval_runtime_profile_hash' => $run->runtime_profile_hash,
            'approval_agent_profile_hash' => $run->agent_profile_hash,
            'approval_security_policy_hash' => $run->security_policy_hash,
            'approval_snapshot_hash' => $approvalSnapshotHash, 'workspace_tree_hash' => $run->checkpoint_tree_sha,
            'invocation_outcome' => ReviewInvocationOutcome::VALID_RESULT, 'result_status' => 'nothing_to_fix',
            'raw_artifact_id' => $artifact->id,
        ]);
        if ($withCoverage) {
            CriterionCoverage::query()->create([
                'id' => (string) Str::uuid(), 'run_id' => $run->id, 'review_result_id' => $result->id,
                'round_number' => 1, 'slot_id' => $slot->slot_id, 'criterion_id' => 'AC-01',
                'status' => 'covered', 'evidence' => 'Gebundener Testnachweis.',
            ]);
        }

        return $result;
    }

    protected function validTicketMarkdown(string $id, string $status = 'todo', string $dependsOn = '[]', string $goal = 'Ziel des Tickets.'): string
    {
        return parent::validTicketMarkdown($id, $status, $dependsOn, $goal)
            ."\n\n## Acceptance Criteria\n\n- [ ] **AC-01** Der Nachweis ist vollständig.\n";
    }

    private function sqliteTrigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        self::assertNotNull($row);
        self::assertIsString($row->sql);

        return $row->sql;
    }

    /** @return array<string, string|null> */
    private function sqliteIndexes(string $table): array
    {
        $indexes = [];
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? ORDER BY name", [$table]) as $row) {
            self::assertIsString($row->name);
            self::assertTrue($row->sql === null || is_string($row->sql));
            $indexes[$row->name] = $row->sql;
        }

        return $indexes;
    }
}
