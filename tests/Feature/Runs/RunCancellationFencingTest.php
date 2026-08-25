<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunCancellationMode;
use App\AI6\Runs\RunCancellationService;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-09: a provider response that returns after a cancellation was bound to
 * the run publishes neither diff nor artifact nor result nor run state.
 */
final class RunCancellationFencingTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_a_late_provider_response_after_a_bound_cancellation_publishes_nothing(): void
    {
        Mail::fake();
        $ticketId = 'AI6-026-FENCE';
        $prepared = $this->preparedImplementationRun($ticketId);
        $run = $prepared['run'];
        $this->publishInProgressReadModel($run, Project::query()->findOrFail($run->project_id), $ticketId);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $operator = $prepared['operator'];

        // While the provider turn is in flight, a human wait opens and the
        // authorized cancellation binds its status operation to the run.
        $adapter = $this->fencingAdapter(function () use ($run, $operator): void {
            $fresh = Run::query()->findOrFail($run->id);
            $job = ExecutionJob::query()->where('run_id', $fresh->id)
                ->where('step_type', 'implement')->firstOrFail();
            $request = $this->app->make(HumanRequestService::class)->open(
                $fresh,
                new HumanRequestProposal(
                    'clarification',
                    'Rückfrage',
                    'Eine Entscheidung wird benötigt.',
                    'Die Umsetzung benötigt eine Auswahl.',
                    'select',
                    [new HumanRequestOption('a', 'Option A')],
                    'a',
                    [],
                    [],
                ),
                (string) Str::uuid(),
                $job->idempotency_key,
            );
            $this->app->make(RunCancellationService::class)->request(
                $request,
                $operator,
                $request->bound_run_version,
                RunCancellationMode::SOFT,
                'Abbruch während des laufenden Agentenschritts.',
                $this->authorization($operator, $request, RunCancellationMode::SOFT),
            );
        });
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);

        $job = $this->executeImplement($run->fresh());

        // The cancellation is bound, and the late response was fenced: no
        // diff, no artifact, no result, no run state came out of the turn.
        $fresh = $run->fresh();
        self::assertNotNull($fresh->pending_status_operation_id);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::HUMAN_QUESTION, $fresh->wait_reason);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count());
        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame('cancelled', HumanRequest::query()->where('run_id', $run->id)
            ->sole()->resolution_state->value);
    }

    private function fencingAdapter(Closure $duringTurn): AgentAdapter
    {
        return new class($duringTurn) implements AgentAdapter
        {
            public int $turns = 0;

            public function __construct(private readonly Closure $duringTurn) {}

            public function result(AgentResultContext $context): string
            {
                return '{}';
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                $this->turns++;
                ($this->duringTurn)();

                // The bytes below are the "late" provider response: they must
                // never be validated or published once the cancellation bound.
                return '{}';
            }
        };
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
        $session->setId('fence-'.$actor->id.'-'.bin2hex(random_bytes(4)));
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
}
