<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\ScopeApprovalService;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/** AC-01, AC-02, AC-03, AC-04, AC-14; TC-01, TC-02 */
final class ScopeApprovalTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    public function test_an_auto_allow_path_is_added_without_any_human_decision(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-AUTO');
        $step = $this->implementStep($run);

        $opened = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->configuration($run),
            'app/AI6/Runs/AutoAllowed.php',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        self::assertNull($opened);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertContains('app/AI6/Runs/AutoAllowed.php', $run->fresh()->effective_scope_snapshot);
        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)->count());
    }

    public function test_a_sensitive_path_opens_exactly_one_scope_approval_request(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-SENSITIVE');
        $step = $this->implementStep($run);

        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->configuration($run),
            'composer.json',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        self::assertNotNull($request);
        self::assertSame('scope_approval', $request->kind);
        self::assertSame(['composer.json'], $request->affected_paths);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $run->fresh()->wait_reason);
        self::assertSame(RunState::WAITING, $run->fresh()->state);
    }

    /** AC-12, TC-11: a scope request on an instruction path is refused outright. */
    public function test_an_instruction_path_scope_request_is_refused_without_a_request(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-INSTR');
        $step = $this->implementStep($run);

        try {
            $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
                $run,
                $this->configuration($run),
                'AGENTS.md',
                false,
                (string) $step->id,
                $step->idempotency_key,
            );
            self::fail('An instruction path must never enter the scope decision path.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('instruction_scope_extension_forbidden', $rejected->reason);
        }

        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)->count());
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertNull($run->fresh()->effective_scope_snapshot);
    }

    /**
     * AC-02/AC-03, TC-01: the third track of plan §8.2 at one and the same
     * path — the trusted project default decides, nothing else.
     */
    public function test_an_unlisted_path_follows_the_trusted_project_default(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-UNLISTED');
        $step = $this->implementStep($run);

        // `scope.unlisted_paths: auto_allow` (the server default) takes the
        // path up without a question and does not block the run.
        $opened = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->configuration($run),
            'docs/unlisted.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );
        self::assertNull($opened);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);
        self::assertContains('docs/unlisted.md', $run->fresh()->effective_scope_snapshot);
        $decision = ScopeDecision::query()->where('run_id', $run->id)->where('path', 'docs/unlisted.md')->firstOrFail();
        self::assertSame('unlisted_auto_allow', $decision->reason);
        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)->count());

    }

    /** The strict value of the same trusted setting at the very same path. */
    public function test_the_strict_unlisted_paths_default_turns_the_same_path_into_a_decision(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-UNLISTED-STRICT');
        $step = $this->implementStep($run);

        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->strictConfiguration($run),
            'docs/unlisted.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        self::assertNotNull($request);
        self::assertSame('scope_approval', $request->kind);
        self::assertSame(['docs/unlisted.md'], $request->affected_paths);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $run->fresh()->wait_reason);
        self::assertNull($run->fresh()->effective_scope_snapshot);
    }

    public function test_approving_the_request_extends_the_effective_scope_and_resumes_the_run(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-APPROVE');
        $step = $this->implementStep($run);
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->strictConfiguration($run),
            'docs/notes.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        $operator = $this->createUser();
        $this->addMembership($operator, $run->fresh()->project()->firstOrFail(), ProjectRole::APPROVER);
        $request->refresh();
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $operator,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'approve',
        );

        $fresh = $run->fresh();
        self::assertSame(RunState::RUNNING, $fresh->state);
        self::assertNull($fresh->wait_reason);
        self::assertContains('docs/notes.md', $fresh->effective_scope_snapshot);
        self::assertSame(1, $fresh->added_scope_paths_count);
    }

    public function test_rejecting_the_request_resumes_the_run_without_extending_the_scope(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-REJECT');
        $step = $this->implementStep($run);
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            $this->strictConfiguration($run),
            'docs/rejected.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        $operator = $this->createUser();
        $this->addMembership($operator, $run->fresh()->project()->firstOrFail(), ProjectRole::APPROVER);
        $request->refresh();
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $operator,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'reject',
        );

        $fresh = $run->fresh();
        self::assertSame(RunState::RUNNING, $fresh->state);
        self::assertNull($fresh->wait_reason);
        self::assertNull($fresh->effective_scope_snapshot);
        self::assertSame(0, $fresh->added_scope_paths_count);
    }

    /** AC-04, TC-02 */
    public function test_exhausting_the_added_scope_path_limit_opens_a_resource_limit_wait_instead(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-LIMIT');
        $limits = $this->app->make(RunLimitPolicy::class)->effective($run);
        $maxAddedScopePaths = $limits['max_added_scope_paths'];

        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        for ($i = 0; $i < $maxAddedScopePaths; $i++) {
            $orchestrator->applyScopeDecision($run->fresh(), 'app/AI6/Runs/Filler'.$i.'.php', true, null, $maxAddedScopePaths, $canonicalJson, 'auto_allow');
        }

        $step = $this->implementStep($run);
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run->fresh(),
            $this->strictConfiguration($run),
            'docs/one-too-many.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );

        self::assertNotNull($request);
        self::assertSame('resource_limit', $request->kind);
        self::assertSame(WaitReason::RESOURCE_LIMIT, $run->fresh()->wait_reason);
    }

    /** AC-04/AC-05, TC-02/TC-03: an authorized increase raises the granted limit and admits the path. */
    public function test_an_authorized_increase_raises_the_scope_path_limit_and_admits_the_path(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-INC');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $limits = $this->app->make(RunLimitPolicy::class);
        $maxAddedScopePaths = $limits->effective($run)['max_added_scope_paths'];
        for ($i = 0; $i < $maxAddedScopePaths; $i++) {
            $orchestrator->applyScopeDecision($run->fresh(), 'app/AI6/Runs/Filler'.$i.'.php', true, null, $maxAddedScopePaths, $canonicalJson, 'auto_allow');
        }

        $step = $this->implementStep($run);
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run->fresh(),
            $this->strictConfiguration($run),
            'docs/one-more.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );
        self::assertSame('resource_limit', $request->kind);

        $this->answerRequest($run, $request, 'increase');

        // The grant raises exactly this limit to the observed demand; the run
        // resumes and the pending path can now enter the effective scope
        // through the same decision seam.
        self::assertSame($maxAddedScopePaths + 1, $limits->effective($run->fresh())['max_added_scope_paths']);
        self::assertSame(RunState::RUNNING, $run->fresh()->state);

        $scopeRequest = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run->fresh(),
            $this->strictConfiguration($run),
            'docs/one-more.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );
        self::assertSame('scope_approval', $scopeRequest->kind);
        $this->answerRequest($run, $scopeRequest, 'approve');

        $fresh = $run->fresh();
        self::assertSame($maxAddedScopePaths + 1, $fresh->added_scope_paths_count);
        self::assertContains('docs/one-more.md', $fresh->effective_scope_snapshot);
    }

    /** AC-04, TC-02: no intervention raises the limit past the immutable server maximum. */
    public function test_an_increase_above_the_server_maximum_is_rejected_without_effect(): void
    {
        Mail::fake();
        $run = $this->startedRun('AI6-020-SCOPE-MAX');
        // The trusted server maximum is lowered for this proof; the approved
        // project value (12) is then capped to 2 at evaluation time.
        config(['ai6.project_config.server_maxima.max_added_scope_paths' => 2]);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $limits = $this->app->make(RunLimitPolicy::class);
        self::assertSame(2, $limits->effective($run)['max_added_scope_paths']);
        foreach (['app/AI6/Runs/One.php', 'app/AI6/Runs/Two.php'] as $path) {
            $orchestrator->applyScopeDecision($run->fresh(), $path, true, null, 2, $canonicalJson, 'auto_allow');
        }

        $step = $this->implementStep($run);
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run->fresh(),
            $this->strictConfiguration($run),
            'docs/beyond-maximum.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );
        self::assertSame('resource_limit', $request->kind);

        try {
            $this->answerRequest($run, $request, 'increase');
            self::fail('An increase above the server maximum must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('limit_above_server_maximum', $rejected->reason);
        }

        $fresh = $run->fresh();
        self::assertSame(2, $limits->effective($fresh)['max_added_scope_paths']);
        self::assertSame(2, $fresh->added_scope_paths_count);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::RESOURCE_LIMIT, $fresh->wait_reason);
    }

    private function answerRequest(Run $run, HumanRequest $request, string $effect): void
    {
        $approver = $this->createUser();
        $this->addMembership($approver, $run->fresh()->project()->firstOrFail(), ProjectRole::APPROVER);
        $request->refresh();
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $approver,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            $effect,
        );
    }

    private function startedRun(string $ticketId): Run
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
        $run = $this->finalizedRun($fixture);

        return $this->finishPreflight($this->bindRunWorkspace($fixture, $run));
    }

    private function implementStep(Run $run): ExecutionJob
    {
        return ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
    }

    private function configuration(Run $run): ProjectConfiguration
    {
        return $this->app->make(EffectiveProjectConfiguration::class)->for($run->fresh()->project()->firstOrFail())->configuration;
    }

    /**
     * The same freigegebene configuration with the strict variant of the third
     * track: `scope.unlisted_paths: require_approval` (plan §8.2).
     */
    private function strictConfiguration(Run $run): ProjectConfiguration
    {
        $values = $this->configuration($run)->values;
        $values['scope']['unlisted_paths'] = 'require_approval';

        return new ProjectConfiguration($values);
    }
}
