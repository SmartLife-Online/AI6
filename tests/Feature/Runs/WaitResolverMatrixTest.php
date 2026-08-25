<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-04: every failure wait introduced by AI6-026 is driven once from its
 * producer through the allowlisted resolver to the resumed bound step, and a
 * resolver outside the allowlist is refused without partial effect.
 */
final class WaitResolverMatrixTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    public function test_retry_resolves_provider_error_into_the_resumed_bound_step(): void
    {
        Mail::fake();
        [$run, $job] = $this->runningImplementationRun('AI6-026-RETRY');
        $request = $this->app->make(HumanRequestService::class)
            ->openFailureRequest($run, $job, WaitReason::PROVIDER_ERROR);

        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::PROVIDER_ERROR, $fresh->wait_reason);
        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);
        self::assertSame(['retry'], $request->allowed_effects);

        // A resolver outside the allowlist is refused without partial effect.
        try {
            $this->answer($request, 'increase');
            self::fail('A non-allowlisted resolver was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('effect_not_offered', $rejected->reason);
        }
        self::assertSame(RunState::WAITING, $run->fresh()->state);
        self::assertSame('open', $request->fresh()->resolution_state->value);
        self::assertDatabaseCount('interventions', 0);

        // The idempotent retry continues exactly the bound step.
        $this->answer($request, 'retry');
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertSame(1, DB::table('jobs')->where('payload', 'like', '%ExecuteRunStep%')->count());
    }

    public function test_new_turn_resolves_invalid_json_into_the_resumed_bound_step(): void
    {
        Mail::fake();
        [$run, $job] = $this->runningImplementationRun('AI6-026-NEWTURN');
        $request = $this->app->make(HumanRequestService::class)
            ->openFailureRequest($run, $job, WaitReason::INVALID_JSON);

        self::assertSame(WaitReason::INVALID_JSON, $run->fresh()->wait_reason);
        self::assertSame(['new_turn'], $request->allowed_effects);

        $this->answer($request, 'new_turn');
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertSame(1, DB::table('jobs')->where('payload', 'like', '%ExecuteRunStep%')->count());
    }

    /** TC-04: the approved profile switch creates a new slot revision with a new session and resumes the bound step. */
    public function test_switch_profile_with_fresh_step_up_revises_the_slot_and_resumes_the_bound_step(): void
    {
        Mail::fake();
        [$run, $job] = $this->runningImplementationRun('AI6-026-PROFILE-OK');
        $slotId = (string) Str::uuid();
        RunAgent::query()->create([
            'run_id' => $run->id,
            'slot_id' => $slotId,
            'approval_slot_id' => $slotId,
            'slot_revision' => 1,
            'is_active' => true,
            'role' => 'quality_review',
            'provider_profile' => 'fake',
            'model' => 'fake-model',
            'effort' => 'high',
            'prompt_profile' => 'security',
            'session_id' => 'session-before-switch',
        ]);
        $this->bindReviewerSnapshot($run, $slotId);
        $request = $this->app->make(HumanRequestService::class)
            ->openFailureRequest($run, $job, WaitReason::PROVIDER_ERROR, $slotId);
        self::assertContains('switch_profile', $request->allowed_effects);

        $this->answer($request, 'switch_profile', $this->authorization($request, 'switch_profile'));

        // AC-07: a new slot revision with a new session; the old slot keeps
        // its own session and result binding, retired but readable.
        $old = RunAgent::query()->where('run_id', $run->id)->where('slot_id', $slotId)->sole();
        self::assertFalse($old->is_active);
        self::assertSame('session-before-switch', $old->session_id);
        $revision = RunAgent::query()->where('run_id', $run->id)
            ->where('approval_slot_id', $slotId)->where('is_active', true)->sole();
        self::assertSame(2, $revision->slot_revision);
        self::assertNotSame($slotId, $revision->slot_id);
        self::assertNotNull($revision->session_id);
        self::assertNotSame('session-before-switch', $revision->session_id);
        self::assertSame('codex-gpt-5.6-terra', $revision->provider_profile);

        // The resolution continues exactly the bound step.
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertSame(ExecutionJobState::PLANNED, $job->fresh()->state);
        self::assertSame(1, DB::table('jobs')->where('payload', 'like', '%ExecuteRunStep%')->count());
    }

    public function test_switch_profile_requires_the_same_fresh_step_up_as_switch_reviewer(): void
    {
        self::assertTrue(HumanRequestService::requiresStepUp('switch_profile'));
        self::assertTrue(HumanRequestService::requiresStepUp('switch_reviewer'));

        Mail::fake();
        [$run, $job] = $this->runningImplementationRun('AI6-026-PROFILE');
        $slotId = (string) Str::uuid();
        RunAgent::query()->create([
            'run_id' => $run->id,
            'slot_id' => $slotId,
            'approval_slot_id' => $slotId,
            'slot_revision' => 1,
            'is_active' => true,
            'role' => 'quality_review',
            'provider_profile' => 'fake',
            'model' => 'fake-model',
            'effort' => 'high',
            'prompt_profile' => 'security',
        ]);
        $request = $this->app->make(HumanRequestService::class)
            ->openFailureRequest($run, $job, WaitReason::INVALID_JSON, $slotId);
        self::assertContains('switch_profile', $request->allowed_effects);

        // Without a fresh proof the profile switch is refused without effect.
        try {
            $this->answer($request, 'switch_profile');
            self::fail('A profile switch without fresh step-up was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('step_up_required', $rejected->reason);
        }
        self::assertSame(RunState::WAITING, $run->fresh()->state);
        self::assertSame('open', $request->fresh()->resolution_state->value);
        self::assertDatabaseCount('interventions', 0);
        self::assertSame(1, RunAgent::query()->where('run_id', $run->id)
            ->where('role', 'quality_review')->count());
    }

    /** @return array{0: Run, 1: ExecutionJob} */
    private function runningImplementationRun(string $ticketId): array
    {
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
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();

        return [$run, $job];
    }

    private function answer(HumanRequest $request, string $effect, ?InterventionAuthorization $authorization = null): void
    {
        $request = $request->fresh() ?? $request;
        $operator = $this->operatorFor($request);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $operator,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            $effect,
            $authorization,
        );
    }

    /** Bind an approval snapshot that carries the current reviewer and one approved alternative. */
    private function bindReviewerSnapshot(Run $run, string $slotId): void
    {
        $snapshot = $run->agent_profile_snapshot ?? [];
        $snapshot['reviewers'] = [
            [
                'id' => $slotId,
                'provider_profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile_id' => 'security',
            ],
            [
                'id' => (string) Str::uuid(),
                'provider_profile' => 'codex-gpt-5.6-terra',
                'model' => 'gpt-5.6-terra',
                'effort' => 'high',
                'prompt_profile_id' => 'tests',
            ],
        ];
        DB::table('runs')->where('id', $run->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    private function authorization(HumanRequest $request, string $effect): InterventionAuthorization
    {
        $request = $request->fresh() ?? $request;
        $actor = $this->operatorFor($request);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('resolver-matrix-'.$actor->id.'-'.bin2hex(random_bytes(4)));
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

    private function operatorFor(HumanRequest $request): User
    {
        return ProjectMembership::query()
            ->where('project_id', $request->project_id)
            ->where('role', ProjectRole::OPERATOR->value)
            ->firstOrFail()->user()->firstOrFail();
    }
}
