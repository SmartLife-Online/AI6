<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\RunCheckpointService;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\FindingDispositionController;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\ReviewReadinessDecision;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Runs\RunFixTurn;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the round-bound fix and re-review loop of AI6-025 over the real seams.
 *
 * The fixture stages a checkpoint through the real checkpoint service and
 * advances the prepared check effect. It does not execute RunCheckStep or its
 * mandatory checker profile; check execution remains covered by CheckStepTest.
 */
trait BuildsFixLoopFixture
{
    protected function executeFix(Run $run, int $round): ExecutionJob
    {
        foreach ([RunImplementation::class, RunFixTurn::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $job = $this->stepJob($run, ExecutionStepType::FIX, $round);
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            fixes: $this->app->make(RunFixTurn::class),
        );

        return $job->fresh() ?? $job;
    }

    protected function executeReviewRound(Run $run, int $round): ExecutionJob
    {
        $this->app->forgetInstance(ReviewRound::class);
        $job = $this->stepJob($run, ExecutionStepType::REVIEW, $round);
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            reviews: $this->app->make(ReviewRound::class),
        );

        return $job->fresh() ?? $job;
    }

    /**
     * Stage the pre-review boundary of one round: a new checkpoint, a supplied
     * review-readiness decision, and the bound phase change. This deliberately
     * does not claim to execute the mandatory checks.
     */
    protected function completeCheckRound(Run $run, string $projectIdentifier, int $round): Run
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $job = $this->stepJob($run, ExecutionStepType::CHECK, $round);
        $owner = str_repeat('c', 32);
        $claimed = $orchestrator->claimStep($job, $owner);
        self::assertNotNull($claimed, 'The check step of round '.$round.' could not be claimed.');

        $fresh = $run->fresh() ?? $run;
        // Staging runs through the effect lock, which is POSIX-only and therefore
        // unavailable on every Windows runner. The commit below stands in for that
        // one staged write; `create()` then binds it through its real
        // branch-advanced path, exactly as it does after a worker crash.
        $this->gitOutput(['add', '--all', '--no-renormalize'], (string) $fresh->worktree_path);
        $this->gitOutput(['commit', '-m', 'AI6 fix round checkpoint'], (string) $fresh->worktree_path);
        $context = new RedactionContext((string) $fresh->project_id, $fresh->id, 'fix-loop-checkpoint');
        $fresh = $this->app->make(RunCheckpointService::class)->create($fresh, $projectIdentifier, $context);
        $fresh = $orchestrator->recordReviewReadiness($fresh->fresh() ?? $fresh, new ReviewReadinessDecision([], []));

        self::assertTrue($orchestrator->applyPreparedStepEffect($fresh, ExecutionStepType::CHECK, $round));
        self::assertTrue($orchestrator->finishStep($claimed, $owner, ExecutionJobState::SUCCEEDED, 'Checks und Reviewbereitschaft abgeschlossen.'));
        DB::table('jobs')->delete();

        return $fresh->fresh() ?? $fresh;
    }

    /**
     * Bind a deterministic adapter whose default scenario also covers the
     * implementation slot of a fix turn.
     *
     * @param  array<string, AgentScenario|list<AgentScenario>>  $slotScenarios
     */
    protected function fixAdapter(AgentScenario $default, array $slotScenarios = []): FakeAgentAdapter
    {
        $adapter = new FakeAgentAdapter($default, slotScenarios: $slotScenarios);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->instance(AgentAdapter::class, $adapter);
        foreach ([
            CredentialRevisionRegistry::class,
            ExecutionHomeManager::class,
            InstructionBindingVerifier::class,
            ReviewRound::class,
            RunImplementation::class,
            RunFixTurn::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }

        return $adapter;
    }

    /** Record an authorized human disposition over the registered route. */
    /** @return TestResponse<Response> */
    protected function disposeAsHuman(Run $run, Finding $finding, string $disposition, string $reason): TestResponse
    {
        $approver = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::APPROVER->value)->firstOrFail()->user()->firstOrFail();
        $this->actingAs($approver);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/finding-disposition', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied($proof, $approver, FindingDispositionController::STEP_UP_ACTION);
        $session->save();

        return $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.runs.findings.disposition', [
                $run->project()->firstOrFail(), $run->id, $finding->id,
            ]), [
                'disposition' => $disposition,
                'reason' => $reason,
                'expected_version' => $run->fresh()?->version,
            ]);
    }

    /**
     * Bind the deterministic scope double for the fix turn.
     *
     * @param  array<string, string>  $writes  path => content
     * @param  list<string>  $changedPaths
     */
    protected function scopedFixAdapter(array $writes, array $changedPaths): AgentAdapter
    {
        $adapter = new ScopedFixAdapter($writes, $changedPaths);
        $this->app->instance(AgentAdapter::class, $adapter);
        foreach ([RunImplementation::class, RunFixTurn::class] as $binding) {
            $this->app->forgetInstance($binding);
        }

        return $adapter;
    }

    /** Answer the one open scope request of the run. */
    protected function answerFixScopeRequest(Run $run, string $effect): void
    {
        $request = HumanRequest::query()->where('run_id', $run->getKey())
            ->where('kind', 'scope_approval')->where('resolution_state', 'open')->firstOrFail();
        $approver = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::APPROVER->value)->firstOrFail()->user()->firstOrFail();
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

    protected function stepJob(Run $run, ExecutionStepType $type, int $round): ExecutionJob
    {
        $key = RunOrchestrator::stepKey($run->id, $type, $round);
        $job = ExecutionJob::query()->where('idempotency_key', $key)->first();
        self::assertInstanceOf(
            ExecutionJob::class,
            $job,
            'No '.$type->value.' step was planned for round '.$round.'.',
        );

        return $job;
    }

    protected function plannedStep(Run $run, ExecutionStepType $type, int $round): bool
    {
        return ExecutionJob::query()
            ->where('idempotency_key', RunOrchestrator::stepKey($run->id, $type, $round))->exists();
    }
}
