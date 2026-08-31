<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Auth\Models\User;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\RunCheckpointService;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\ReviewReadinessDecision;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;

trait BuildsReviewRoundFixture
{
    use BuildsImplementationTurnFixture;
    use BuildsRunWorkspaceGitFixture;

    /** @var list<string> */
    protected array $reviewSlotIds = [];

    #[After]
    public function removeReviewGitRunnerFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);
        $this->reviewSlotIds = [(string) Str::uuid(), (string) Str::uuid()];

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([
                [
                    'id' => $this->reviewSlotIds[0],
                    'profile' => 'fake',
                    'model' => 'fake-model',
                    'effort' => 'high',
                    'prompt_profile' => 'security',
                ],
                [
                    'id' => $this->reviewSlotIds[1],
                    'profile' => 'codex-gpt-5.6-terra',
                    'model' => 'gpt-5.6-terra',
                    'effort' => 'high',
                    'prompt_profile' => 'tests',
                ],
            ]),
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            $attentionUser?->getKey(),
            'manual',
        );
    }

    /**
     * @param  list<InstructionCandidate>  $instructionCandidates
     * @return array{run: Run, worktree: string}
     */
    protected function preparedReviewRun(
        string $ticketId,
        array $instructionCandidates = [],
        bool $enableIndependentFallback = true,
        AgentScenario $implementationScenario = AgentScenario::NO_CHANGE_REQUIRED,
    ): array {
        $agentProfiles = config('ai6.agent_profiles');
        $agentProfiles['codex-gpt-5.6-terra']['capability_status'] = 'available';
        $agentProfiles['grok-cli-review']['capability_status'] = $enableIndependentFallback ? 'available' : 'unchecked';
        config([
            'ai6.agent_profiles' => $agentProfiles,
            'ai6.credential_revisions.codex_cli' => 'test-v1',
            'ai6.credential_revisions.grok_cli' => 'test-v1',
        ]);
        foreach ([
            AgentProfileRegistry::class,
            ReviewerSlotFactory::class,
            EffectiveProjectConfiguration::class,
            ApprovalSnapshotFactory::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $this->app->instance(HardenedGitRunner::class, $this->runWorkspaceRunner($this->runWorkspaceRoot()));

        $prepared = $this->preparedImplementationRun(
            $ticketId,
            scenario: $implementationScenario,
            coherentGitBinding: true,
            instructionCandidates: $instructionCandidates,
        );
        $implementation = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $implementation->state, (string) $implementation->failure_code);

        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $prepared['run']->fresh();
        if ($implementationScenario !== AgentScenario::NO_CHANGE_REQUIRED) {
            $this->gitOutput(['add', '--all', '--no-renormalize'], (string) $run->worktree_path);
            $this->gitOutput(['commit', '-m', 'AI6 implementation checkpoint'], (string) $run->worktree_path);
            $context = new RedactionContext((string) $run->project_id, $run->id, 'review-implementation-checkpoint');
            $run = $this->app->make(RunCheckpointService::class)->create(
                $run,
                (string) $run->project()->value('project_identifier'),
                $context,
            );
        }
        $run = $orchestrator->recordReviewReadiness($run, new ReviewReadinessDecision([], []));
        $check = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::CHECK->value)->firstOrFail();
        $owner = str_repeat('c', 32);
        $claimed = $orchestrator->claimStep($check, $owner);
        self::assertNotNull($claimed);
        self::assertTrue($orchestrator->applyPreparedStepEffect($run, ExecutionStepType::CHECK));
        self::assertTrue($orchestrator->finishStep($claimed, $owner, ExecutionJobState::SUCCEEDED, 'Reviewbereitschaft vorbereitet.'));
        DB::table('jobs')->delete();
        // Git for Windows can leave a racily-clean linked-worktree stat entry
        // after the fixture checkout. A normal status refresh proves the bytes
        // are unchanged and stabilizes the real hardened no-optional-locks read.
        self::assertSame('', $this->gitOutput(['status', '--porcelain=v2'], $prepared['worktree']));

        return ['run' => $run->fresh(), 'worktree' => $prepared['worktree']];
    }

    /**
     * @param  array<string, AgentScenario|list<AgentScenario>>  $scenarios
     * @param  list<string>  $additionalPathProbes
     */
    protected function reviewAdapter(array $scenarios, array $additionalPathProbes = []): FakeAgentAdapter
    {
        $adapter = new FakeAgentAdapter(
            AgentScenario::SUCCESS,
            slotScenarios: $scenarios,
            additionalPathProbes: $additionalPathProbes,
        );
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->instance(AgentAdapter::class, $adapter);
        foreach ([
            CredentialRevisionRegistry::class,
            ExecutionHomeManager::class,
            InstructionBindingVerifier::class,
            ReviewRound::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }

        return $adapter;
    }

    protected function executeReview(Run $run): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::REVIEW->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            reviews: $this->app->make(ReviewRound::class),
        );

        return $job->fresh() ?? $job;
    }

    /** @return list<RunAgent> */
    protected function orchestratorReviewSlots(Run $run): array
    {
        return $this->app->make(RunOrchestrator::class)->materializeReviewSlots($run);
    }

    /** @return list<string> */
    protected function directoryEntries(string $path): array
    {
        return array_values(array_filter(scandir($path) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true)));
    }
}
