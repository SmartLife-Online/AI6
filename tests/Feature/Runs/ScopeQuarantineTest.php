<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-04, TC-05: quarantine of already changed additional paths.
 *
 * The isolated turn produces changes outside the effective scope; each such
 * path is preserved as a redacted artifact before any decision, an approval
 * takes it back through the same import seam, and a rejection removes only the
 * AI6-own change while foreign repository content stays untouched.
 */
final class ScopeQuarantineTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    /**
     * @param  array<string, string|null>  $writes  path => content, null deletes
     * @param  list<string>  $changedPaths
     */
    private function scopedAdapter(array $writes, array $changedPaths): AgentAdapter
    {
        return new class($writes, $changedPaths) implements AgentAdapter
        {
            /**
             * @param  array<string, string|null>  $writes
             * @param  list<string>  $changedPaths
             */
            public function __construct(private array $writes, private array $changedPaths) {}

            public function result(AgentResultContext $context): string
            {
                return json_encode([
                    'schema_version' => 'ai6.agent.v1',
                    'status' => 'completed',
                    'summary' => 'Deterministisches Scope-Testergebnis.',
                    'prompt_snapshot_hash' => $context->promptSnapshot->hash,
                    'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
                    'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
                    'decisions' => [['key' => 'd1', 'title' => 'Umsetzung', 'rationale' => 'Deterministische Entscheidung.']],
                    'changed_paths' => $this->changedPaths,
                    'open_manual_gates' => [],
                    'implementation_summary' => [
                        'changed_components' => ['Example'],
                        'decisions' => ['Deterministische Entscheidung.'],
                        'assumptions' => [],
                        'deviations' => [],
                        'known_limits' => [],
                        'tests' => [],
                        'review_focus' => $this->changedPaths,
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                foreach ($this->writes as $path => $content) {
                    $target = rtrim($isolatedTree, '/\\').'/'.$path;
                    if ($content === null) {
                        @unlink($target);

                        continue;
                    }
                    $directory = dirname($target);
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    file_put_contents($target, $content);
                }

                return $this->result($context);
            }
        };
    }

    private function bindAdapter(AgentAdapter $adapter): void
    {
        $this->app->forgetInstance(RunImplementation::class);
        $this->app->instance(AgentAdapter::class, $adapter);
    }

    private function answerScopeRequest(Run $run, string $effect): void
    {
        $request = HumanRequest::query()
            ->where('run_id', $run->getKey())
            ->where('kind', 'scope_approval')
            ->where('resolution_state', 'open')
            ->firstOrFail();
        $approver = $this->createUser();
        $this->addMembership($approver, $run->project()->firstOrFail(), ProjectRole::APPROVER);
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

    public function test_partial_approval_imports_only_approved_paths_and_preserves_rejected_content(): void
    {
        Mail::fake();
        $fixture = $this->preparedImplementationRun('AI6-020-QUARANTINE');
        $run = $fixture['run'];
        $worktree = $fixture['worktree'];

        // Foreign repository content outside the effective scope: the rejection
        // later must leave these bytes untouched (TC-05).
        self::assertTrue(mkdir($worktree.'/docs', 0700));
        self::assertNotFalse(file_put_contents($worktree.'/docs/b.md', "fremder Originalinhalt\n"));

        $this->bindAdapter($this->scopedAdapter([
            'app/Example.php' => "<?php\n\n// scoped change\n",
            'docs/a.md' => "vorgeschlagen a\n",
            'docs/b.md' => "AI6-eigene Änderung an b\n",
        ], ['app/Example.php', 'docs/a.md', 'docs/b.md']));

        // Turn 1: the first undecided out-of-scope path parks the run behind
        // exactly one scope_approval request; nothing is imported yet.
        $job = $this->executeImplement($run);
        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $fresh->wait_reason);
        self::assertSame("<?php\n\n// original\n", file_get_contents($worktree.'/app/Example.php'));
        self::assertFileDoesNotExist($worktree.'/docs/a.md');
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)->count());

        // Approve docs/a.md; turn 2 quarantines docs/b.md and asks again.
        $this->answerScopeRequest($fresh, 'approve');
        $job = $this->executeImplement($run);
        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $run->fresh()->wait_reason);
        self::assertSame(2, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)->count());

        // Reject docs/b.md; turn 3 imports the approved subset only.
        $this->answerScopeRequest($run->fresh(), 'reject');
        $job = $this->executeImplement($run);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);

        $final = $run->fresh();
        self::assertSame("<?php\n\n// scoped change\n", file_get_contents($worktree.'/app/Example.php'));
        self::assertSame("vorgeschlagen a\n", file_get_contents($worktree.'/docs/a.md'));
        // Only the AI6-own change was dropped; the foreign bytes survive (AC-06).
        self::assertSame("fremder Originalinhalt\n", file_get_contents($worktree.'/docs/b.md'));

        self::assertSame(1, $final->added_scope_paths_count);
        self::assertContains('docs/a.md', $final->effective_scope_snapshot);
        self::assertNotContains('docs/b.md', $final->effective_scope_snapshot);
        self::assertSame(['app/Example.php', 'docs/a.md'], $final->actual_changed_paths_snapshot);
        self::assertNotNull($final->actual_changed_paths_hash);

        $decisions = ScopeDecision::query()->where('run_id', $run->id)->orderBy('path')->get();
        self::assertSame(['docs/a.md', 'docs/b.md'], $decisions->pluck('path')->all());
        self::assertSame(['approved', 'rejected'], $decisions->pluck('outcome')->all());
        // The audit trail survives: both quarantined artifacts stay readable.
        self::assertSame(2, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)->count());
    }

    public function test_an_auto_allowed_path_imports_without_any_decision(): void
    {
        Mail::fake();
        $fixture = $this->preparedImplementationRun('AI6-020-QUAR-AUTO');
        $run = $fixture['run'];

        // tests/** is auto_allow in the freigegebene Projektkonfiguration.
        $this->bindAdapter($this->scopedAdapter([
            'tests/NewTest.php' => "<?php\n\n// neuer Test\n",
        ], ['tests/NewTest.php']));

        $job = $this->executeImplement($run);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        $fresh = $run->fresh();
        self::assertSame(1, $fresh->added_scope_paths_count);
        self::assertContains('tests/NewTest.php', $fresh->effective_scope_snapshot);
        self::assertSame(0, HumanRequest::query()->where('run_id', $run->id)->count());
        self::assertSame("<?php\n\n// neuer Test\n", file_get_contents($fixture['worktree'].'/tests/NewTest.php'));
        $decision = ScopeDecision::query()->where('run_id', $run->id)->firstOrFail();
        self::assertSame('approved', $decision->outcome);
        self::assertNull($decision->human_request_id);
    }
}
