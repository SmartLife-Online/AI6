<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunLimitConsumption;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ImplementationInstructionTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    /** TC-05 */
    public function test_instruction_and_runtime_drift_stop_before_the_provider_turn(): void
    {
        foreach (['instruction_binding_drift', 'runtime_profile_drift'] as $code) {
            $prepared = $this->preparedImplementationRun('AI6-019-TC05-'.($code === 'instruction_binding_drift' ? 'IBD' : 'RPD'));
            $this->app->make(RunOrchestrator::class)->ensureImplementationSlot($prepared['run']);
            $this->app->make(RunOrchestrator::class)->bindImplementationSession(
                $prepared['run'],
                (string) RunAgent::query()->where('run_id', $prepared['run']->id)->value('slot_id'),
                'session-old',
            );
            if ($code === 'instruction_binding_drift') {
                $snapshot = $prepared['run']->instruction_snapshot ?? [];
                $provider = array_key_first($snapshot) ?? 'fake';
                $snapshot[$provider]['instruction_snapshot_hash'] = str_repeat('e', 64);
                DB::table('runs')->where('id', $prepared['run']->id)->update([
                    'instruction_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            } else {
                $runtime = $prepared['run']->runtime_profile_snapshot ?? [];
                $id = array_key_first($runtime) ?? 'fake-v1';
                $runtime[$id]['hash'] = str_repeat('f', 64);
                DB::table('runs')->where('id', $prepared['run']->id)->update([
                    'runtime_profile_snapshot' => json_encode($runtime, JSON_THROW_ON_ERROR),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            }
            $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
            $job = $this->executeImplement($prepared['run']->fresh());
            self::assertSame(ExecutionJobState::FAILED, $job->state, $code);
            $expected = $code === 'runtime_profile_drift'
                ? ['runtime_profile_drift', 'runtime_profile_not_server_bound']
                : [$code];
            self::assertContains($job->failure_code, $expected, $code);
            self::assertNull(RunAgent::query()->where('run_id', $prepared['run']->id)->value('session_id'), $code);
            self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'), $code);
        }
    }

    /** TC-05 */
    public function test_instruction_and_runtime_drift_stop_before_resume(): void
    {
        Mail::fake();
        foreach (['instruction_binding_drift', 'runtime_profile_drift'] as $code) {
            $prepared = $this->preparedImplementationRun(
                'AI6-019-TC05-R-'.($code === 'instruction_binding_drift' ? 'IBD' : 'RPD'),
                scenario: AgentScenario::HUMAN_REQUEST,
            );
            $adapter = $this->app->make(FakeAgentAdapter::class);
            $opened = $this->executeImplement($prepared['run']);
            self::assertSame(ExecutionJobState::WAITING, $opened->state, $code);
            self::assertSame(1, $adapter->turnCount, $code);
            $session = RunAgent::query()->where('run_id', $prepared['run']->id)->value('session_id');
            self::assertIsString($session, $code);

            $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
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

            if ($code === 'instruction_binding_drift') {
                $snapshot = $prepared['run']->fresh()->instruction_snapshot ?? [];
                $provider = array_key_first($snapshot) ?? 'fake';
                $snapshot[$provider]['instruction_snapshot_hash'] = str_repeat('e', 64);
                DB::table('runs')->where('id', $prepared['run']->id)->update([
                    'instruction_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            } else {
                $runtime = $prepared['run']->fresh()->runtime_profile_snapshot ?? [];
                $id = array_key_first($runtime) ?? 'fake-v1';
                $runtime[$id]['hash'] = str_repeat('f', 64);
                DB::table('runs')->where('id', $prepared['run']->id)->update([
                    'runtime_profile_snapshot' => json_encode($runtime, JSON_THROW_ON_ERROR),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            }

            $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
            $job = $this->executeImplement($prepared['run']->fresh());
            self::assertSame(ExecutionJobState::FAILED, $job->state, $code);
            $expected = $code === 'runtime_profile_drift'
                ? ['runtime_profile_drift', 'runtime_profile_not_server_bound']
                : [$code];
            self::assertContains($job->failure_code, $expected, $code);
            self::assertSame(1, $adapter->turnCount, $code);
            self::assertNull(RunAgent::query()->where('run_id', $prepared['run']->id)->value('session_id'), $code);
            self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'), $code);
        }
    }

    /** TC-06 */
    public function test_an_instruction_update_uses_the_structured_channel_and_rejects_later_scope(): void
    {
        Mail::fake();
        $prepared = $this->preparedImplementationRun('AI6-019-TC06', ['AGENTS.md', 'app/Example.php']);
        $originalHash = $prepared['run']->instruction_hash;
        $adapter = new class implements AgentAdapter
        {
            public function result(AgentResultContext $context): string
            {
                $document = json_decode((new FakeAgentAdapter)->result($context), true, 16, JSON_THROW_ON_ERROR);
                $document['changed_paths'] = ['AGENTS.md'];
                $document['instruction_patch'] = [
                    'schema_version' => 'ai6.instruction-patch.v1',
                    'path' => 'AGENTS.md',
                    'expected_blob_sha' => null,
                    'format' => 'utf8_file_replacement_v1',
                    'content_base64' => base64_encode("# new\n"),
                    'content_length' => 6,
                    'content_sha256' => hash('sha256', "# new\n"),
                ];

                return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                return $this->result($context);
            }
        };
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);
        file_put_contents($prepared['worktree'].'/AGENTS.md', "# old\n");
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame("# new\n", (string) file_get_contents($prepared['worktree'].'/AGENTS.md'));
        self::assertSame($originalHash, $prepared['run']->fresh()->instruction_hash);
        $raw = RunArtifact::query()
            ->where('run_id', $prepared['run']->id)
            ->where('kind', 'provider_raw')
            ->firstOrFail();
        $payload = (string) file_get_contents(config('ai6.run_artifacts.root').DIRECTORY_SEPARATOR.$raw->storage_reference);
        $document = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(
            $prepared['run']->instruction_snapshot[array_key_first($prepared['run']->instruction_snapshot ?? []) ?? '']['instruction_snapshot_hash'] ?? null,
            $document['instruction_snapshot_hash'] ?? null,
        );

        $late = $this->preparedImplementationRun('AI6-019-TC06B', ['app/Example.php']);
        $lateAdapter = new class implements AgentAdapter
        {
            public function result(AgentResultContext $context): string
            {
                $document = json_decode((new FakeAgentAdapter)->result($context), true, 16, JSON_THROW_ON_ERROR);
                $document['instruction_patch'] = [
                    'schema_version' => 'ai6.instruction-patch.v1',
                    'path' => 'AGENTS.md',
                    'expected_blob_sha' => null,
                    'format' => 'utf8_file_replacement_v1',
                    'content_base64' => base64_encode("# late\n"),
                    'content_length' => 7,
                    'content_sha256' => hash('sha256', "# late\n"),
                ];

                return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                return $this->result($context);
            }
        };
        $this->app->instance(AgentAdapter::class, $lateAdapter);
        $this->app->forgetInstance(RunImplementation::class);
        $failed = $this->executeImplement($late['run']);
        self::assertSame(ExecutionJobState::WAITING, $failed->state);
        self::assertSame(3, RunLimitConsumption::query()->where('run_id', $late['run']->id)->count());
        self::assertSame(
            ['waiting', 'invalid_json', 'invalid_json'],
            [
                $late['run']->fresh()->state->value,
                $late['run']->fresh()->wait_reason?->value,
                HumanRequest::query()->where('run_id', $late['run']->id)->sole()->kind,
            ],
        );
        self::assertFileDoesNotExist($late['worktree'].'/AGENTS.md');
    }
}
