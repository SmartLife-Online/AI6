<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunState;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ImplementationLimitTurnTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    /** TC-07 */
    public function test_each_import_limit_imports_at_the_boundary_and_rejects_one_above(): void
    {
        $this->assertBoundaryImport(
            'AI6-019-TC07-FILES',
            ['max_changed_files' => 1],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-FILES-OVER',
            ['max_changed_files' => 1],
            $this->changingAdapter(
                ['app/Example.php', 'app/Other.php'],
                "<?php\n\n// fake-agent-change\n",
                ['app/Other.php' => "<?php\n"],
            ),
            ['app/Example.php', 'app/Other.php'],
        );

        $atBytes = str_repeat('a', 20);
        $this->assertBoundaryImport(
            'AI6-019-TC07-BYTES',
            ['max_changed_bytes' => 20],
            $this->changingAdapter(['app/Example.php'], $atBytes),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-BYTES-OVER',
            ['max_changed_bytes' => 20],
            $this->changingAdapter(['app/Example.php'], $atBytes.'x'),
        );

        $this->assertBoundaryImport(
            'AI6-019-TC07-ART',
            ['max_artifacts' => 2],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-ART-OVER',
            ['max_artifacts' => 1],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );

        $this->assertBoundaryImport(
            'AI6-019-TC07-ARTB',
            ['max_artifact_bytes' => 20000],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-ARTB-OVER',
            ['max_artifact_bytes' => 20],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );

        $this->assertBoundaryImport(
            'AI6-019-TC07-TOTAL',
            ['max_total_artifact_bytes' => 20000],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-TOTAL-OVER',
            ['max_total_artifact_bytes' => 20],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );

        $this->assertBoundaryImport(
            'AI6-019-TC07-OUT',
            ['max_provider_output_bytes' => 20000],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
        $this->assertRejectedImport(
            'AI6-019-TC07-OUT-OVER',
            ['max_provider_output_bytes' => 20],
            $this->changingAdapter(['app/Example.php'], "<?php\n\n// fake-agent-change\n"),
        );
    }

    /** TC-08 */
    public function test_an_authorized_increase_lets_the_resumed_step_import(): void
    {
        Mail::fake();
        $prepared = $this->preparedImplementationRun('AI6-019-TC08-TURN');
        $this->overrideLimits($prepared['run'], ['max_provider_output_bytes' => 20]);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $waiting = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::WAITING, $waiting->state, (string) $waiting->failure_code);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(0, RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count());

        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $prepared['operator'],
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'increase',
            $this->authorization($prepared['operator'], $request, 'increase'),
        );
        $effective = $this->app->make(RunLimitPolicy::class)->effective($prepared['run']->fresh());
        self::assertGreaterThan(20, $effective['max_provider_output_bytes']);

        $resumed = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::SUCCEEDED, $resumed->state, (string) $resumed->failure_code);
        self::assertSame(RunState::RUNNING, $prepared['run']->fresh()->state);
        self::assertStringContainsString('fake-agent-change', (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(2, RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count());
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function assertBoundaryImport(string $ticketId, array $limits, AgentAdapter $adapter): void
    {
        $files = ['app/Example.php'];
        if (str_contains($ticketId, 'FILES') && ! str_contains($ticketId, 'OVER')) {
            $files = ['app/Example.php'];
        }
        $prepared = $this->preparedImplementationRun($ticketId, $files);
        $this->overrideLimits($prepared['run'], $limits);
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);
        $job = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, $ticketId.': '.(string) $job->failure_code);
        self::assertNotSame('<?php'."\n\n".'// original'."\n", (string) file_get_contents($prepared['worktree'].'/app/Example.php'), $ticketId);
        self::assertGreaterThan(0, RunArtifact::query()->where('run_id', $prepared['run']->id)->count(), $ticketId);
    }

    /**
     * @param  array<string, int>  $limits
     * @param  list<string>  $files
     */
    private function assertRejectedImport(string $ticketId, array $limits, AgentAdapter $adapter, array $files = ['app/Example.php']): void
    {
        $prepared = $this->preparedImplementationRun($ticketId, $files);
        $this->overrideLimits($prepared['run'], $limits);
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $job = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::WAITING, $job->state, $ticketId.': '.(string) $job->failure_code);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'), $ticketId);
        self::assertSame(0, RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count(), $ticketId);
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function overrideLimits(Run $run, array $limits): void
    {
        $snapshot = $run->agent_profile_snapshot ?? [];
        $snapshot['limits'] = array_merge(is_array($snapshot['limits'] ?? null) ? $snapshot['limits'] : [], $limits);
        DB::table('runs')->where('id', $run->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    private function authorization(User $actor, HumanRequest $request, string $effect): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('implementation-limit-'.$actor->id.'-'.bin2hex(random_bytes(4)));
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
     * @param  list<string>  $changedPaths
     * @param  array<string, string>  $extraWrites
     */
    private function changingAdapter(array $changedPaths, string $example, array $extraWrites = []): AgentAdapter
    {
        return new class($changedPaths, $example, $extraWrites) implements AgentAdapter
        {
            /** @param  list<string>  $changedPaths
             * @param  array<string, string>  $extraWrites
             */
            public function __construct(
                private readonly array $changedPaths,
                private readonly string $example,
                private readonly array $extraWrites,
            ) {}

            public function result(AgentResultContext $context): string
            {
                $document = json_decode((new FakeAgentAdapter)->result($context), true, 16, JSON_THROW_ON_ERROR);
                $document['changed_paths'] = $this->changedPaths;

                return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                $root = rtrim(str_replace('\\', '/', $isolatedTree), '/');
                file_put_contents($root.'/app/Example.php', $this->example);
                foreach ($this->extraWrites as $path => $bytes) {
                    $target = $root.'/'.$path;
                    $directory = dirname($target);
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    file_put_contents($target, $bytes);
                }

                return $this->result($context);
            }
        };
    }
}
