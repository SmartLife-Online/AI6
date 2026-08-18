<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type HumanRequestFixture array{
 *     request: HumanRequest,
 *     run: Run,
 *     project: Project,
 *     operator: User,
 *     attention: User,
 *     slot: string,
 *     proposal: HumanRequestProposal
 * }
 */
trait BuildsHumanRequestFixture
{
    use BuildsFinalizedRunFixture;

    /** @param array{operator: User, project: Project, approval: TicketApproval, attention: ?User} $fixture */
    protected function bindRunWorkspace(array $fixture, Run $run): Run
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$fixture['project']->project_identifier.'/'.$run->id,
            '/managed/worktrees/'.$run->id,
        );

        return $orchestrator->bindCheckpoint($run, $run->version, str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64));
    }

    protected function finishPreflight(Run $run): Run
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::PREFLIGHT->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class));
        DB::table('jobs')->delete();

        return $run->refresh();
    }

    protected function humanRequestProposal(
        string $title = 'Rückfrage',
        string $message = 'Eine Entscheidung wird benötigt.',
        string $whyNeeded = 'Die gebundene Umsetzung benötigt eine Auswahl.',
        string $label = 'Option A',
        string $path = 'app/Example.php',
    ): HumanRequestProposal {
        return new HumanRequestProposal(
            'clarification',
            $title,
            $message,
            $whyNeeded,
            'select',
            [new HumanRequestOption('a', $label), new HumanRequestOption('b', 'Option B')],
            'a',
            [$path],
            [],
        );
    }

    /** @return HumanRequestFixture */
    protected function openedHumanRequest(
        string $ticketId,
        ?HumanRequestProposal $proposal = null,
        bool $bindCheckpoint = true,
    ): array {
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
        if ($bindCheckpoint) {
            $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $run));
            $stepType = ExecutionStepType::IMPLEMENT;
        } else {
            $run = $this->app->make(RunOrchestrator::class)->transition(
                $run,
                $run->version,
                RunState::RUNNING,
                $run->phase,
            );
            $stepType = ExecutionStepType::PREFLIGHT;
        }
        $slot = (string) Str::uuid();
        $proposal ??= $this->humanRequestProposal();
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', $stepType->value)->firstOrFail();
        $request = $this->app->make(HumanRequestService::class)->open($run, $proposal, $slot, $step->idempotency_key);

        return [
            'request' => $request->fresh(),
            'run' => $run->refresh(),
            'project' => $fixture['project']->refresh(),
            'operator' => $fixture['operator'],
            'attention' => $attention,
            'slot' => $slot,
            'proposal' => $proposal,
        ];
    }
}
