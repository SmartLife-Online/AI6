<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ContractChangeService;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunLimitConsumption;
use App\AI6\Runs\RunCancellationMode;
use App\AI6\Runs\RunCancellationService;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RunInterventionLimitTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    public function test_agent_invocations_are_consumed_before_effect_once_per_bound_key(): void
    {
        Mail::fake();
        $run = $this->openedHumanRequest('AI6-026-INVOCATION-LIMIT')['run'];
        $limits = $this->app->make(RunLimitPolicy::class);
        $maximum = $limits->effective($run)[ImportLimit::MAX_AGENT_INVOCATIONS->value];

        foreach (range(1, $maximum) as $invocation) {
            self::assertNull($limits->consume($run, ImportLimit::MAX_AGENT_INVOCATIONS, 'turn:'.$invocation));
        }
        self::assertNull($limits->consume($run, ImportLimit::MAX_AGENT_INVOCATIONS, 'turn:'.$maximum));
        self::assertSame($maximum, RunLimitConsumption::query()->where('run_id', $run->id)->count());

        $exceeded = $limits->consume($run, ImportLimit::MAX_AGENT_INVOCATIONS, 'turn:'.($maximum + 1));
        self::assertInstanceOf(ImportLimitResult::class, $exceeded);
        self::assertSame($maximum + 1, $exceeded->observed);
        self::assertSame($maximum, $exceeded->maximum);
        self::assertSame($maximum, RunLimitConsumption::query()->where('run_id', $run->id)->count());
    }

    public function test_soft_cancel_is_status_bound_and_releases_the_run_only_after_own_confirmed_operation(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-026-SOFT-CANCEL');
        $this->publishInProgressReadModel($opened['run'], $opened['project'], 'AI6-026-SOFT-CANCEL');
        $request = $opened['request']->fresh();
        $service = $this->app->make(RunCancellationService::class);

        $intervention = $service->request(
            $request,
            $opened['operator'],
            $request->bound_run_version,
            RunCancellationMode::SOFT,
            'Run kontrolliert auf todo zurücksetzen.',
            $this->authorization($opened['operator'], $request, RunCancellationMode::SOFT),
        );

        $run = $opened['run']->fresh();
        self::assertSame(RunState::WAITING, $run->state);
        self::assertNotNull($run->pending_status_operation_id);
        self::assertSame($run->id, $opened['project']->fresh()->active_run_id);
        self::assertSame($intervention->status_operation_id, $run->pending_status_operation_id);
        self::assertSame('todo', TicketMutation::query()->findOrFail($run->pending_status_operation_id)->target_status);

        $operation = $this->confirmStatusOperation($run->pending_status_operation_id, $opened['project']);
        $cancelled = $service->reconcileOperation($operation);
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($opened['project']->fresh()->active_run_id);
        self::assertSame($cancelled->id, $service->reconcileOperation($operation)?->id);
    }

    public function test_block_cancel_requires_approver_step_up_and_stale_decisions_leave_no_audit(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-026-BLOCK-CANCEL');
        $this->publishInProgressReadModel($opened['run'], $opened['project'], 'AI6-026-BLOCK-CANCEL');
        $request = $opened['request']->fresh();
        $service = $this->app->make(RunCancellationService::class);

        foreach ([
            [$opened['operator'], null, 'strong_authorization_required'],
            [$opened['attention'], null, 'step_up_required'],
            [$opened['attention'], $this->authorization($opened['attention'], $request, RunCancellationMode::BLOCK), 'stale_run_version'],
        ] as $index => [$actor, $authorization, $reason]) {
            try {
                $service->request(
                    $request,
                    $actor,
                    $index === 2 ? $request->bound_run_version - 1 : $request->bound_run_version,
                    RunCancellationMode::BLOCK,
                    'Ticket fachlich blockieren.',
                    $authorization,
                );
                self::fail('An unauthorized or stale block decision was accepted.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame($reason, $rejected->reason);
            }
        }

        self::assertDatabaseCount('interventions', 0);
        self::assertNull($opened['run']->fresh()->pending_status_operation_id);
        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
    }

    public function test_block_cancel_queues_the_blocked_status_target(): void
    {
        $this->assertStrongCancelTarget(RunCancellationMode::BLOCK, 'blocked');
    }

    public function test_hard_cancel_queues_the_cancelled_status_target(): void
    {
        $this->assertStrongCancelTarget(RunCancellationMode::HARD, 'cancelled');
    }

    public function test_confirmed_branch_publication_forbids_every_generic_cancel_mode(): void
    {
        Mail::fake();
        $opened = $this->openedHumanRequest('AI6-026-PUSH-GUARD');
        self::assertSame(1, DB::table('runs')->where('id', $opened['run']->id)->update([
            'confirmed_branch_publication_oid' => str_repeat('a', 64),
            'version' => DB::raw('version + 1'),
        ]));
        $request = $opened['request']->fresh();

        foreach (RunCancellationMode::cases() as $mode) {
            $actor = $mode->requiresApprover() ? $opened['attention'] : $opened['operator'];
            try {
                $this->app->make(RunCancellationService::class)->request(
                    $request,
                    $actor,
                    $request->bound_run_version,
                    $mode,
                    'Nach Veröffentlichung unzulässig.',
                    $this->authorization($actor, $request, $mode),
                );
                self::fail('A generic cancellation after confirmed branch publication was accepted.');
            } catch (HumanRequestRejected $rejected) {
                self::assertSame('cancel_after_push_forbidden', $rejected->reason);
            }
        }

        self::assertDatabaseCount('interventions', 0);
        self::assertNull($opened['run']->fresh()->pending_status_operation_id);
    }

    public function test_cancel_compare_and_swap_conflict_keeps_the_lock_and_opens_one_refresh_decision(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-CAS-CONFLICT';
        $opened = $this->openedHumanRequest($ticketId);
        $this->publishInProgressReadModel($opened['run'], $opened['project'], $ticketId);
        $request = $opened['request']->fresh();
        $service = $this->app->make(RunCancellationService::class);
        $service->request(
            $request,
            $opened['operator'],
            $request->bound_run_version,
            RunCancellationMode::SOFT,
            'Run nach OID-Konflikt kontrolliert zurücksetzen.',
            $this->authorization($opened['operator'], $request, RunCancellationMode::SOFT),
        );
        $operation = ControlOperation::query()->findOrFail($opened['run']->fresh()->pending_status_operation_id);

        $parked = $service->recordConflict($operation);
        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::GIT_CONFLICT, $parked->wait_reason);
        self::assertSame($opened['run']->id, $opened['project']->fresh()->active_run_id);
        self::assertDatabaseCount('human_requests', 2);
        $refresh = HumanRequest::query()->where('run_id', $opened['run']->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame(['refresh_expected_oid'], $refresh->allowed_effects);

        $service->recordConflict($operation);
        self::assertDatabaseCount('human_requests', 2);
        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
    }

    /** AC-14: the released conflict binding lets the refreshed decision reach the terminal run. */
    public function test_a_refreshed_reauthorized_decision_after_conflict_reaches_the_terminal_run(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-CAS-RETRY';
        $opened = $this->openedHumanRequest($ticketId);
        $this->publishInProgressReadModel($opened['run'], $opened['project'], $ticketId);
        $request = $opened['request']->fresh();
        $service = $this->app->make(RunCancellationService::class);
        $service->request(
            $request,
            $opened['operator'],
            $request->bound_run_version,
            RunCancellationMode::SOFT,
            'Erster Abbruchversuch vor dem Konflikt.',
            $this->authorization($opened['operator'], $request, RunCancellationMode::SOFT),
        );
        $conflicted = ControlOperation::query()->findOrFail($opened['run']->fresh()->pending_status_operation_id);

        // The conflict supersedes the failed binding instead of pinning it.
        $parked = $service->recordConflict($conflicted);
        self::assertSame(WaitReason::GIT_CONFLICT, $parked->wait_reason);
        self::assertNull($parked->pending_status_operation_id);

        // The worker ends the conflicted operation and releases the project
        // operation lock, exactly as the executor's terminal-conflict path does.
        $lease = $this->app->make(ProjectOperationLease::class);
        $token = $lease->claim($conflicted->refresh(), str_repeat('e', 32));
        self::assertIsInt($token);
        ControlOperationResult::query()->create([
            'control_operation_id' => $conflicted->id,
            'outcome' => 'failed',
            'result_binding' => str_repeat('0', 64),
            'safe_summary' => 'Compare-and-Swap-Konflikt terminal aufgezeichnet.',
        ]);
        self::assertSame(1, ControlOperation::query()->whereKey($conflicted->id)->update([
            'state' => ControlOperationState::FAILED,
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertTrue($lease->release($conflicted->id, $conflicted->project_id, $token));

        // The refreshed, re-authorized decision binds a fresh operation …
        $refresh = HumanRequest::query()->where('run_id', $opened['run']->id)
            ->where('resolution_state', 'open')->sole();

        // The marker effect never resumes the conflict wait through a plain
        // answer: a hand-crafted POST is refused without any effect.
        $this->actingAs($opened['operator'])
            ->post(route('projects.human-requests.answer', [$opened['project'], $refresh->id]), [
                'run_version' => $refresh->bound_run_version,
                'ticket_contract' => $refresh->bound_ticket_contract,
                'checkpoint' => $refresh->bound_checkpoint,
                'scope' => $refresh->bound_scope,
                'agent_slot' => $refresh->bound_agent_slot,
                'requested_effect' => $refresh->bound_requested_effect,
                'chosen_effect' => 'refresh_expected_oid',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('chosen_effect');
        $stillParked = $opened['run']->fresh();
        self::assertSame(RunState::WAITING, $stillParked->state);
        self::assertSame(WaitReason::GIT_CONFLICT, $stillParked->wait_reason);
        self::assertSame('open', $refresh->fresh()->resolution_state->value);
        self::assertDatabaseCount('interventions', 1);

        $service->request(
            $refresh,
            $opened['operator'],
            $refresh->bound_run_version,
            RunCancellationMode::SOFT,
            'Nach OID-Refresh erneut autorisiert.',
            $this->authorization($opened['operator'], $refresh, RunCancellationMode::SOFT),
        );
        $rebound = $opened['run']->fresh()->pending_status_operation_id;
        self::assertNotNull($rebound);
        self::assertNotSame($conflicted->id, $rebound);

        // … and the saga actually reaches the terminal run this time.
        $operation = $this->confirmStatusOperation($rebound, $opened['project']);
        $cancelled = $service->reconcileOperation($operation);
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($opened['project']->fresh()->active_run_id);
    }

    /** TC-13/AC-12: after the blocking saga the run is terminal and a restart without a new approval is refused. */
    public function test_the_blocking_saga_ends_terminal_and_a_restart_without_new_approval_is_refused(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-BLOCK-TERMINAL';
        $opened = $this->openedHumanRequest($ticketId);
        $this->publishInProgressReadModel($opened['run'], $opened['project'], $ticketId);
        $request = $opened['request']->fresh();
        $service = $this->app->make(RunCancellationService::class);

        $service->request(
            $request,
            $opened['attention'],
            $request->bound_run_version,
            RunCancellationMode::BLOCK,
            'Ticket fachlich blockieren.',
            $this->authorization($opened['attention'], $request, RunCancellationMode::BLOCK),
        );
        $operationId = $opened['run']->fresh()->pending_status_operation_id;
        self::assertSame('blocked', TicketMutation::query()->findOrFail($operationId)->target_status);

        $operation = $this->confirmStatusOperation($operationId, $opened['project']);
        $cancelled = $service->reconcileOperation($operation);
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($opened['project']->fresh()->active_run_id);

        // A resume of the old wait is refused without a new authorized path.
        try {
            $this->app->make(RunOrchestrator::class)->resumeWait(
                $cancelled,
                $cancelled->version,
                (string) $request->bound_step_key,
                WaitReason::HUMAN_QUESTION,
            );
            self::fail('A terminal blocked run accepted a resume.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('human_question_not_waiting', $conflict->reason);
        }

        // The parked step of the cancelled run never executes again.
        $job = ExecutionJob::query()->where('run_id', $opened['run']->id)
            ->where('idempotency_key', $request->bound_step_key)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class));
        self::assertSame(RunState::CANCELLED, $opened['run']->fresh()->state);
        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->fresh()->state);

        // A second decision over the resolved request is refused without effect.
        try {
            $service->request(
                $request->fresh(),
                $opened['attention'],
                $request->bound_run_version,
                RunCancellationMode::HARD,
                'Nachgelagerter Versuch.',
                $this->authorization($opened['attention'], $request, RunCancellationMode::HARD),
            );
            self::fail('A resolved intervention request accepted a second decision.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('request_already_resolved', $rejected->reason);
        }
    }

    /** TC-15: the panel carries the run.intervene step-up form, and the block succeeds over the real route. */
    public function test_the_panel_offers_step_up_and_a_block_succeeds_over_the_real_route(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-PANEL';
        $opened = $this->openedHumanRequest($ticketId);
        $this->publishInProgressReadModel($opened['run'], $opened['project'], $ticketId);
        $request = $opened['request']->fresh();
        $approver = $opened['attention'];
        $payload = [
            'run_version' => $request->bound_run_version,
            'ticket_contract' => $request->bound_ticket_contract,
            'checkpoint' => $request->bound_checkpoint,
            'scope' => $request->bound_scope,
            'agent_slot' => $request->bound_agent_slot,
            'requested_effect' => $request->bound_requested_effect,
            'chosen_effect' => 'block',
            'reason' => 'Fachlich blockieren über das Panel.',
        ];

        $this->actingAs($approver)
            ->get(route('projects.human-requests.show', [$opened['project'], $request->id]))
            ->assertOk()
            ->assertSee('TOTP-Code für Step-up')
            ->assertSee('Fachlich blockieren');

        // Without a fresh proof the panel answers with a German form error.
        $this->actingAs($approver)
            ->post(route('projects.human-requests.answer', [$opened['project'], $request->id]), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('chosen_effect');
        self::assertDatabaseCount('interventions', 0);
        self::assertNull($opened['run']->fresh()->pending_status_operation_id);

        // With a satisfied step-up in the session the same POST binds the saga.
        $this->actingAs($approver);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied($proof, $approver, HumanRequestAnswerController::STEP_UP_ACTION);
        $session->save();
        $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [$opened['project'], $request->id]), $payload)
            ->assertRedirect(route('projects.runs.show', [$opened['project'], $opened['run']->id]));

        $operationId = Run::query()->findOrFail($opened['run']->id)->pending_status_operation_id;
        self::assertNotNull($operationId);
        self::assertSame('blocked', TicketMutation::query()->findOrFail($operationId)->target_status);
    }

    /** AC-05: base drift opens the abort request, and the controlled abort ends in a terminal run. */
    public function test_base_drift_opens_the_abort_request_and_the_controlled_abort_ends_terminal(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-BASE-DRIFT';
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $attention = $this->createUser(['email' => 'attention-'.$ticketId.'@example.test']);
        $fixture = $this->completedApproval($ticketId, attentionUser: $attention);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));

        // The run's bound base drifts away from the control head; the request
        // path answers external drift as git_base_changed instead of a
        // contract-change wait.
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'run_base_sha' => str_repeat('9', 64),
            'version' => DB::raw('version + 1'),
        ]));
        $run = $run->fresh();
        $parked = $this->app->make(ContractChangeService::class)->request($run, $fixture['project']->refresh());
        self::assertSame(RunState::WAITING, $parked->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $parked->wait_reason);

        $request = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->sole();
        self::assertSame('git_base_changed', $request->kind);
        self::assertSame(['controlled_abort'], $request->allowed_effects);

        // The marker effect never resolves the wait outside the saga.
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
                'controlled_abort',
            );
            self::fail('The controlled abort marker was answerable outside the cancellation saga.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('legacy_cancel_forbidden', $rejected->reason);
        }

        // The controlled abort runs through the one status-mutation saga.
        $project = $fixture['project']->refresh();
        $this->publishInProgressReadModel($run->fresh(), $project, $ticketId);
        $service = $this->app->make(RunCancellationService::class);
        $service->request(
            $request->fresh(),
            $fixture['operator'],
            $request->bound_run_version,
            RunCancellationMode::SOFT,
            'Basisdrift kontrolliert abbrechen.',
            $this->authorization($fixture['operator'], $request, RunCancellationMode::SOFT),
        );
        $operationId = $run->fresh()->pending_status_operation_id;
        self::assertNotNull($operationId);
        $operation = $this->confirmStatusOperation($operationId, $project);
        $cancelled = $service->reconcileOperation($operation);
        self::assertSame(RunState::CANCELLED, $cancelled->state);
        self::assertNull($project->fresh()->active_run_id);
    }

    private function assertStrongCancelTarget(RunCancellationMode $mode, string $target): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-'.str_replace('_', '-', strtoupper($mode->value));
        $opened = $this->openedHumanRequest($ticketId);
        $this->publishInProgressReadModel($opened['run'], $opened['project'], $ticketId);
        $request = $opened['request']->fresh();

        $this->app->make(RunCancellationService::class)->request(
            $request,
            $opened['attention'],
            $request->bound_run_version,
            $mode,
            'Stark autorisierte Statusentscheidung.',
            $this->authorization($opened['attention'], $request, $mode),
        );

        $operationId = $opened['run']->fresh()->pending_status_operation_id;
        self::assertNotNull($operationId);
        self::assertSame($target, TicketMutation::query()->findOrFail($operationId)->target_status);
        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
        self::assertSame($opened['run']->id, $opened['project']->fresh()->active_run_id);
    }

    private function publishInProgressReadModel(Run $run, Project $project, string $ticketId): void
    {
        $content = $this->validTicketMarkdown($ticketId, 'in_progress');
        $blob = hash('sha256', 'blob '.strlen($content)."\0".$content);
        $readModel = TicketReadModel::query()->where('project_id', $project->id)
            ->where('relative_path', 'tickets/'.$ticketId.'.md')->firstOrFail();
        self::assertSame(1, TicketReadModel::query()->whereKey($readModel->id)->update([
            'control_operation_id' => $run->status_operation_id,
            'control_commit' => $project->control_oid,
            'blob_sha' => $blob,
            'redacted_content' => $content,
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function authorization(User $actor, HumanRequest $request, RunCancellationMode $mode): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('run-cancel-'.$actor->id.'-'.bin2hex(random_bytes(4)));
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
            [$request->run_id, $request->id, $request->bound_run_version, $mode->value],
        );
    }

    private function confirmStatusOperation(string $operationId, Project $project): ControlOperation
    {
        $operation = ControlOperation::query()->findOrFail($operationId);
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $commit = str_repeat('d', 64);
        self::assertSame(1, TicketMutation::query()->whereKey($operation->id)->update([
            'prepared_commit_oid' => $commit,
            'prepared_attempt_token' => $attemptToken,
            'updated_at' => now(),
        ]));
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $commit,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => $commit,
            'safe_summary' => 'Testabschluss der Abbruch-Statusoperation.',
        ]);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release(
            $operation->id,
            $operation->project_id,
            $attemptToken,
        ));
        self::assertSame(1, Project::query()->whereKey($project->id)->update([
            'control_oid' => $commit,
            'control_binding_version' => DB::raw('control_binding_version + 1'),
        ]));

        return $operation->refresh();
    }
}
