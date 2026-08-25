<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunLimitConsumption;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ImplementationTurnTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    /** TC-01 */
    public function test_fake_success_imports_a_diff_and_other_scenarios_do_not(): void
    {
        Mail::fake();
        $prepared = $this->preparedImplementationRun('AI6-019-TC01-OK');
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame(RunState::RUNNING, $prepared['run']->fresh()->state);
        self::assertStringContainsString('fake-agent-change', (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(2, RunArtifact::query()->where('run_id', $prepared['run']->id)->count());

        foreach ([
            [AgentScenario::HUMAN_REQUEST, ExecutionJobState::WAITING, RunState::WAITING, WaitReason::HUMAN_QUESTION],
            [AgentScenario::INVALID_JSON, ExecutionJobState::WAITING, RunState::WAITING, WaitReason::INVALID_JSON],
            [AgentScenario::PROVIDER_ERROR, ExecutionJobState::WAITING, RunState::WAITING, WaitReason::PROVIDER_ERROR],
        ] as [$scenario, $step, $runState, $wait]) {
            $other = $this->preparedImplementationRun('AI6-019-TC01-'.strtoupper(str_replace('_', '-', $scenario->value)), scenario: $scenario);
            $original = (string) file_get_contents($other['worktree'].'/app/Example.php');
            $finished = $this->executeImplement($other['run']);
            self::assertSame($step, $finished->state, $scenario->value);
            self::assertSame($runState, $other['run']->fresh()->state, $scenario->value);
            self::assertSame($wait, $other['run']->fresh()->wait_reason, $scenario->value);
            self::assertSame($original, (string) file_get_contents($other['worktree'].'/app/Example.php'), $scenario->value);
            self::assertSame(1, HumanRequest::query()->where('run_id', $other['run']->id)->count());
            self::assertSame(
                $scenario === AgentScenario::HUMAN_REQUEST ? 1 : 3,
                RunLimitConsumption::query()->where('run_id', $other['run']->id)
                    ->where('limit_name', 'max_agent_invocations')->count(),
            );
        }
    }

    /** TC-02 */
    public function test_a_reported_path_without_an_actual_change_is_rejected(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC02-PATH');
        $adapter = new class implements AgentAdapter
        {
            public function result(AgentResultContext $context): string
            {
                $document = json_decode((new FakeAgentAdapter)->result($context), true, 16, JSON_THROW_ON_ERROR);
                $document['changed_paths'] = ['app/Missing.php'];

                return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                return $this->result($context);
            }
        };
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('reported_path_mismatch', $job->failure_code, (string) $job->fresh()?->failure_code);
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
    }

    /** TC-09 */
    public function test_no_change_required_needs_an_empty_server_diff(): void
    {
        $empty = $this->preparedImplementationRun('AI6-019-TC09-OK', scenario: AgentScenario::NO_CHANGE_REQUIRED);
        $job = $this->executeImplement($empty['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(RunState::RUNNING, $empty['run']->fresh()->state);
        self::assertStringContainsString('original', (string) file_get_contents($empty['worktree'].'/app/Example.php'));

        $adapter = new FakeAgentAdapter(AgentScenario::NO_CHANGE_WITH_DIFF);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->instance(AgentAdapter::class, $adapter);
        $dirty = $this->preparedImplementationRun('AI6-019-TC09-BAD', scenario: AgentScenario::NO_CHANGE_WITH_DIFF);
        $failed = $this->executeImplement($dirty['run']);
        self::assertSame(ExecutionJobState::FAILED, $failed->state);
        self::assertSame('no_change_diff', $failed->failure_code);
        self::assertStringContainsString('original', (string) file_get_contents($dirty['worktree'].'/app/Example.php'));
    }

    /** TC-10 */
    public function test_a_new_ticket_never_reuses_a_previous_session(): void
    {
        $first = $this->preparedImplementationRun('AI6-019-TC10-A');
        $this->executeImplement($first['run']);
        $firstSession = RunAgent::query()->where('run_id', $first['run']->id)->where('role', 'implementation')->value('session_id');
        self::assertIsString($firstSession);

        $second = $this->preparedImplementationRun('AI6-019-TC10-B');
        $this->executeImplement($second['run']);
        $secondSession = RunAgent::query()->where('run_id', $second['run']->id)->where('role', 'implementation')->value('session_id');
        self::assertIsString($secondSession);
        self::assertNotSame($firstSession, $secondSession);
    }

    /** TC-11 */
    public function test_an_authorized_answer_resumes_the_same_slot_once(): void
    {
        Mail::fake();
        $prepared = $this->preparedImplementationRun('AI6-019-TC11', scenario: AgentScenario::HUMAN_REQUEST);
        $this->executeImplement($prepared['run']);
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        $slot = RunAgent::query()->where('run_id', $prepared['run']->id)->where('role', 'implementation')->firstOrFail();
        self::assertNotNull($slot->session_id);

        $this->actingAs($prepared['operator'])->post(
            route('projects.human-requests.answer', [$prepared['project'], $request->id]),
            [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => 'a',
            ],
        )->assertRedirect();

        self::assertSame($slot->session_id, $slot->fresh()->session_id);
        self::assertSame(RunState::RUNNING, $prepared['run']->fresh()->state);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        self::assertSame(1, DB::table('jobs')->count());

        $this->actingAs($prepared['operator'])->post(
            route('projects.human-requests.answer', [$prepared['project'], $request->id]),
            [
                'run_version' => $request->bound_run_version,
                'ticket_contract' => $request->bound_ticket_contract,
                'checkpoint' => $request->bound_checkpoint,
                'scope' => $request->bound_scope,
                'agent_slot' => $request->bound_agent_slot,
                'requested_effect' => $request->bound_requested_effect,
                'chosen_effect' => 'a',
            ],
        )->assertRedirect()->assertSessionHasErrors('chosen_effect');
        self::assertSame(1, DB::table('jobs')->count());

        $continuation = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->instance(FakeAgentAdapter::class, $continuation);
        $this->app->instance(AgentAdapter::class, $continuation);
        $this->app->forgetInstance(RunImplementation::class);
        $resumed = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::SUCCEEDED, $resumed->state, (string) $resumed->failure_code);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $prepared['run']->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
        self::assertSame(1, $continuation->turnCount);
        self::assertStringContainsString('fake-agent-change', (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(2, RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count());
    }

    /** TC-12 */
    public function test_the_bound_prompt_snapshot_is_the_only_prompt_source(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC12');
        $boundPrompt = (string) (($prepared['run']->prompt_snapshot ?? [])['rendered_prompts']['implementation'] ?? '');
        self::assertNotSame('', $boundPrompt);
        $this->executeImplement($prepared['run']);
        $adapter = $this->app->make(FakeAgentAdapter::class);
        self::assertSame($boundPrompt, $adapter->lastRenderedImplementationPrompt);
        $summary = RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'implementation_summary')->firstOrFail();
        $bytes = file_get_contents(config('ai6.run_artifacts.root').DIRECTORY_SEPARATOR.$summary->storage_reference);
        self::assertIsString($bytes);
        $payload = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $boundPrompt), $payload['rendered_implementation_prompt_sha256']);

        $other = $this->preparedImplementationRun('AI6-019-TC12-TAMPER');
        $snapshot = $other['run']->prompt_snapshot ?? [];
        $snapshot['rendered_prompts']['implementation'] = ((string) $snapshot['rendered_prompts']['implementation'])."\nmanipulated";
        DB::table('runs')->where('id', $other['run']->id)->update([
            'prompt_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        $failed = $this->executeImplement($other['run']->fresh());
        self::assertSame(ExecutionJobState::FAILED, $failed->state);
        self::assertSame('prompt_binding_mismatch', $failed->failure_code);
        self::assertStringContainsString('original', (string) file_get_contents($other['worktree'].'/app/Example.php'));

        $renderers = [];
        $catalogs = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app/AI6'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'class PromptRenderer')) {
                $renderers[] = $file->getPathname();
            }
            if (str_contains($source, 'class PromptCatalog')) {
                $catalogs[] = $file->getPathname();
            }
        }
        self::assertCount(1, $renderers);
        self::assertCount(1, $catalogs);
        self::assertTrue(class_exists(PromptRenderer::class));
        self::assertTrue(class_exists(PromptCatalog::class));

        $page = (string) file_get_contents(dirname(__DIR__, 3).'/app/AI6/Runs/RunTimelinePage.php');
        foreach (['RunOrchestrator', 'HardenedGitRunner', 'ControlProcessRunner', 'ExecuteRunStep'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $page);
        }
    }
}
