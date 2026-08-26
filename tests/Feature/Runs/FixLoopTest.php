<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingReviewStatus;
use App\AI6\Reviews\FixContextPackage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\FindingStatus;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class FixLoopTest extends TicketUiTestCase
{
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    /** TC-01: one blocking finding drives exactly one fix turn and one complete new review round. */
    public function test_a_single_blocking_finding_runs_one_fix_turn_and_a_complete_new_review_round(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC01');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);

        $finding = Finding::query()->where('run_id', $run->id)->sole();
        $firstCheckpoint = (string) $run->fresh()?->checkpoint_tree_sha;
        self::assertTrue($this->blocks($finding, $run));
        self::assertSame(RunPhase::FIX, $run->fresh()?->phase);
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 1));

        $fix = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $fix->state, (string) $fix->failure_code);
        self::assertStringContainsString(
            'fake-agent-fix',
            (string) file_get_contents($prepared['worktree'].'/app/Example.php'),
        );

        $run = $this->completeCheckRound($run, $identifier, 2);
        self::assertNotSame($firstCheckpoint, (string) $run->checkpoint_tree_sha);

        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        // Every required slot ran again on the new checkpoint; none was skipped.
        $second = ReviewResult::query()->where('run_id', $run->id)->where('round_number', 2)->get();
        self::assertCount(2, $second);
        self::assertEqualsCanonicalizing($this->reviewSlotIds, $second->pluck('slot_id')->all());
        self::assertSame([(string) $run->fresh()?->checkpoint_tree_sha], $second->pluck('checkpoint_tree_sha')->unique()->all());

        // Each required slot classified the presented prior finding exactly once,
        // and the fix turn's own assessment stays a separately sourced entry.
        $statuses = FindingStatus::query()->where('finding_id', $finding->id)
            ->where('source_role', 'quality_review')->get();
        self::assertCount(2, $statuses);
        self::assertEqualsCanonicalizing($this->reviewSlotIds, $statuses->pluck('slot_id')->all());
        self::assertSame(['fixed'], $statuses->pluck('status')->map(static fn ($status): string => $status->value)->unique()->all());
        self::assertSame(1, FindingStatus::query()->where('finding_id', $finding->id)
            ->where('source_role', 'implementation')->count());

        // The complete confirmation resolves the blockade through the one disposition seam.
        self::assertFalse($this->blocks($finding->fresh(), $run->fresh()));
        self::assertFalse($this->plannedStep($run, ExecutionStepType::FIX, 2));
    }

    /** TC-02: two blocking findings reach the fix package with their source and both slots review again. */
    public function test_two_blocking_findings_reach_the_fix_package_and_both_slots_review_again(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC02');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);

        $findings = Finding::query()->where('run_id', $run->id)->get();
        self::assertCount(2, $findings);
        $nonBlocking = $findings->first()->replicate();
        $nonBlocking->id = (string) Str::uuid();
        $nonBlocking->local_id = 'suggestion-not-for-fix';
        $nonBlocking->original_disposition = FindingOriginalDisposition::SUGGESTION;
        $nonBlocking->save();
        $humanDisposed = $findings->first()->replicate();
        $humanDisposed->id = (string) Str::uuid();
        $humanDisposed->local_id = 'human-disposed-not-for-fix';
        $humanDisposed->save();
        $this->disposeAsHuman($run, $humanDisposed, 'not_applicable', 'Dieser Befund ist menschlich disponiert.')
            ->assertRedirect();

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $fixPrompt = $adapter->lastRenderedImplementationPrompt;
        foreach ($findings as $finding) {
            self::assertStringContainsString($finding->id, $fixPrompt);
            self::assertStringContainsString($finding->slot_id, $fixPrompt);
        }
        $package = $this->app->make(FixContextPackage::class)->forRun($run->fresh() ?? $run);
        foreach ([$nonBlocking->id, $humanDisposed->id] as $excluded) {
            self::assertStringNotContainsString($excluded, $fixPrompt);
            self::assertNotContains($excluded, $package['finding_ids']);
        }

        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        self::assertEqualsCanonicalizing(
            $this->reviewSlotIds,
            ReviewResult::query()->where('run_id', $run->id)->where('round_number', 2)->pluck('slot_id')->all(),
        );
        // Every earlier finding is classified once by every required reviewer slot.
        $expectedStatuses = Finding::query()->where('run_id', $run->id)->where('round_number', 1)->count()
            * count($this->reviewSlotIds);
        self::assertSame($expectedStatuses, FindingStatus::query()->where('run_id', $run->id)
            ->where('round_number', 2)->where('source_role', 'quality_review')->count());
    }

    /** TC-03: a regression the fix newly introduced is reported although no old finding names it. */
    public function test_a_regression_introduced_by_the_fix_is_reported_as_a_new_finding(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC03');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $original = Finding::query()->where('run_id', $run->id)->sole();

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);

        $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::REGRESSION_AFTER_FIX,
            $this->reviewSlotIds[1] => AgentScenario::REGRESSION_AFTER_FIX,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        $regressions = Finding::query()->where('run_id', $run->id)->where('round_number', 2)->get();
        self::assertCount(2, $regressions);
        self::assertSame(['app/Regression.php'], $regressions->pluck('file')->unique()->all());
        self::assertNotSame($original->file, $regressions->first()?->file);

        // The old finding was confirmed as fixed, yet the new one keeps the run blocked.
        self::assertSame(
            ['fixed'],
            FindingStatus::query()->where('finding_id', $original->id)->where('source_role', 'quality_review')
                ->get()->pluck('status')->map(static fn ($status): string => $status->value)->unique()->all(),
        );
        self::assertTrue($this->blocks($regressions->first(), $run->fresh()));
        self::assertSame(RunPhase::FIX, $run->fresh()?->phase);
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 2));
    }

    /** TC-06: a rejection by the fix turn is evidence only; only a human disposition unblocks. */
    public function test_an_implementation_rejection_stays_evidence_until_a_human_disposition(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC06A');
        $run = $prepared['run'];

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finding = Finding::query()->where('run_id', $run->id)->sole();

        // The fix turn declares the finding inapplicable and changes nothing.
        $this->fixAdapter(AgentScenario::REJECTS_FINDING);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);

        $rejection = FindingStatus::query()->where('finding_id', $finding->id)
            ->where('source_role', 'implementation')->sole();
        self::assertSame(FindingReviewStatus::NOT_APPLICABLE, $rejection->status);
        self::assertNull($rejection->review_result_id);

        // The declaration resolves nothing: no disposition exists and the run stays blocked.
        self::assertSame(0, FindingDisposition::query()->where('finding_id', $finding->id)->count());
        self::assertTrue($this->blocks($finding->fresh(), $run->fresh()));
        self::assertNotSame('ready', $run->fresh()?->review_readiness_state);

        // The rejection is visible in the read-only timeline as its own source.
        $viewer = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $this->actingAs($viewer)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]))
            ->assertOk()
            ->assertSee('data-status-source="implementation"', false)
            ->assertSee('Implementierungsagent');

        $this->disposeAsHuman($run, $finding, 'not_applicable', 'Der Befund betrifft den freigegebenen Vertrag nicht.')
            ->assertRedirect();

        self::assertSame(1, FindingDisposition::query()->where('finding_id', $finding->id)->count());
        self::assertFalse($this->blocks($finding->fresh(), $run->fresh()));
    }

    /** TC-06: a reviewer calling a blocking finding inapplicable does not resolve it either. */
    public function test_a_reviewer_rejection_does_not_resolve_the_blockade(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC06B');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finding = Finding::query()->where('run_id', $run->id)->sole();

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);

        // Both reviewers report nothing to fix and call the prior finding inapplicable.
        $this->fixAdapter(AgentScenario::REJECTS_FINDING);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        self::assertSame(
            ['not_applicable'],
            FindingStatus::query()->where('finding_id', $finding->id)->where('source_role', 'quality_review')
                ->get()->pluck('status')->map(static fn ($status): string => $status->value)->unique()->all(),
        );
        self::assertSame(0, FindingDisposition::query()->where('finding_id', $finding->id)->count());
        self::assertTrue($this->blocks($finding->fresh(), $run->fresh()));
        self::assertSame(RunPhase::FIX, $run->fresh()?->phase);
    }

    /** TC-09: the fix continues the bound session and a changed binding ends named. */
    public function test_the_fix_continues_the_session_and_a_changed_binding_ends_named(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC09');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);

        $session = $this->implementationSlot($run)->session_id;
        self::assertIsString($session);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        self::assertSame($session, $this->implementationSlot($run)->session_id, 'The fix turn started a new session.');

        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        self::assertTrue($this->plannedStep($run, ExecutionStepType::FIX, 2));

        // A runtime profile that no longer matches the approval must not resume.
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'runtime_profile_hash' => str_repeat('f', 64),
            'version' => DB::raw('version + 1'),
        ]));
        $adapter = $this->fixAdapter(AgentScenario::SUCCESS);
        $failed = $this->executeFix($run, 2);

        self::assertSame(ExecutionJobState::FAILED, $failed->state);
        self::assertSame('snapshot_binding_changed', $failed->failure_code);
        self::assertNull($this->implementationSlot($run)->session_id, 'The session survived a changed binding.');
        self::assertSame(0, $adapter->turnCount, 'The provider ran under a changed binding.');
    }

    /** TC-10: the fix prompt comes from the approval-bound template; drift aborts before the provider. */
    public function test_the_fix_prompt_is_approval_bound_and_drift_aborts_before_the_provider(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC10');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);

        $adapter = $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finding = Finding::query()->where('run_id', $run->id)->sole();

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $prompt = $adapter->lastRenderedImplementationPrompt;
        $entry = $this->app->make(PromptCatalog::class)->entry('fix');
        self::assertStringContainsString(explode('{{', $entry->template)[0], $prompt);
        self::assertStringContainsString($finding->id, $prompt);
        self::assertSame(
            hash('sha256', $entry->template),
            ($run->fresh()->prompt_snapshot ?? [])['fix_prompt_binding']['template_sha256'],
        );

        // A fix binding that no longer matches the catalog ends named before any provider call.
        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);
        $run = $run->fresh() ?? $run;
        $snapshot = $run->prompt_snapshot;
        $snapshot['fix_prompt_binding']['entry_version'] = '99';
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'prompt_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
        ]));
        $drifted = $this->fixAdapter(AgentScenario::SUCCESS);

        $failed = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::FAILED, $failed->state);
        self::assertSame('fix_prompt_binding_mismatch', $failed->failure_code);
        self::assertSame(0, $drifted->turnCount, 'The provider ran under a drifted prompt binding.');
    }

    /** TC-11: a redelivered fix step of the same round creates no second round and no second provider call. */
    public function test_a_redelivered_fix_step_creates_no_second_round_and_no_second_provider_call(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC11');
        $run = $prepared['run'];

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);

        $adapter = $this->fixAdapter(AgentScenario::SUCCESS);
        $job = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame(1, $adapter->turnCount);

        // The stored intent binds the round, the rendered prompt and the finding context.
        $intent = json_decode((string) $job->intent, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('import_fix_result', $intent['effect']);
        self::assertSame(1, $intent['step_number']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', (string) $intent['finding_context_sha256']);

        $this->executeFix($run, 1);

        self::assertSame(1, $adapter->turnCount, 'A completed fix step invoked the provider twice.');
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FIX->value)->count());
    }

    /** TC-12: the loop registers no wait reason and the round display stays a read view. */
    public function test_the_loop_adds_no_wait_reason_and_the_round_display_stays_a_read_view(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC12');
        $run = $prepared['run'];
        $identifier = $this->projectIdentifier($run);
        $expectedReasons = [
            'human_question', 'resource_limit', 'scope_approval', 'contract_change', 'check_failure',
            'review_limit', 'provider_error', 'invalid_json', 'git_base_changed', 'git_conflict',
            'manual_report', 'status_sync',
        ];
        self::assertSame($expectedReasons, $this->app->make(WaitReasonRegistry::class)->registeredReasons());

        $firstRoundAdapter = $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $secondRoundAdapter = $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        self::assertSame($expectedReasons, $this->app->make(WaitReasonRegistry::class)->registeredReasons());

        foreach ([ExecutionStepType::FIX, ExecutionStepType::REVIEW] as $type) {
            $numbers = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', $type->value)
                ->orderBy('step_number')->pluck('step_number')->all();
            self::assertSame($type === ExecutionStepType::FIX ? [1] : [1, 2], $numbers);
            self::assertSame(count($numbers), count(array_unique($numbers)));
        }
        self::assertSame(3, $firstRoundAdapter->turnCount, 'Die erste Reviewrunde und ihr Fixturn wurden nicht genau einmal aufgerufen.');
        self::assertSame(2, $secondRoundAdapter->turnCount, 'Jeder Slot der zweiten Reviewrunde wurde nicht genau einmal aufgerufen.');

        $project = $run->project()->firstOrFail();
        $viewer = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $versionBefore = $run->fresh()?->version;
        $response = $this->actingAs($viewer)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        $response->assertSee('Findingstatus je Re-Review-Runde');
        $response->assertSee('data-review-round="2"', false);
        // Reading the page changed no run state and queued no execution step.
        self::assertSame($versionBefore, $run->fresh()?->version);
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** TC-05: the fix routes every additional path through the one scope policy. */
    public function test_the_fix_scope_matrix_matches_the_implementation_turn(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-025-TC05');
        $run = $prepared['run'];
        $worktree = $prepared['worktree'];

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $countBefore = (int) $run->fresh()->added_scope_paths_count;

        $sensitive = 'database/migrations/2026_01_01_000000_fix_probe.php';
        $this->scopedFixAdapter([
            'app/Example.php' => "<?php\n\n// fix in scope\n",
            'app/Extra.php' => "<?php\n\n// auto allowed\n",
            'docs/extra.md' => "unlisted\n",
            $sensitive => "<?php\n\n// sensitive\n",
        ], ['app/Example.php', 'app/Extra.php', 'docs/extra.md', $sensitive]);

        // The sensitive path is never taken automatically: the fix parks on the
        // same scope decision the implementation turn uses.
        $job = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        self::assertSame(WaitReason::SCOPE_APPROVAL, $run->fresh()?->wait_reason);
        self::assertFileDoesNotExist($worktree.'/'.$sensitive);
        // Every undecided path is preserved before any decision, so the proposed
        // sensitive change stays readable evidence without reaching the worktree.
        self::assertGreaterThan(0, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)->count());

        $this->answerFixScopeRequest($run->fresh(), 'approve');
        $job = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);

        $final = $run->fresh();
        self::assertInstanceOf(Run::class, $final);
        // The auto-allowed, the unlisted and the approved sensitive path all count
        // against the one released path limit.
        self::assertSame($countBefore + 3, (int) $final->added_scope_paths_count);
        foreach (['app/Extra.php', 'docs/extra.md', $sensitive] as $path) {
            self::assertContains($path, $final->effective_scope_snapshot);
            self::assertFileExists($worktree.'/'.$path);
        }
        // All three run through the one scope decision seam ...
        self::assertSame(
            ['app/Extra.php', $sensitive, 'docs/extra.md'],
            ScopeDecision::query()->where('run_id', $run->id)->orderBy('path')->pluck('path')->all(),
        );
        self::assertSame(['approved', 'approved', 'approved'], ScopeDecision::query()
            ->where('run_id', $run->id)->orderBy('path')->pluck('outcome')->all());
        // ... yet only the sensitive one ever asked a human.
        self::assertSame(1, HumanRequest::query()->where('run_id', $run->id)
            ->where('kind', 'scope_approval')->count());
    }

    /** TC-11: a crash between import and publication doubles no effect. */
    public function test_a_crash_between_import_and_publication_doubles_no_effect(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC11B');
        $run = $prepared['run'];
        $worktree = $prepared['worktree'];

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);

        $this->fixAdapter(AgentScenario::SUCCESS);
        $job = $this->executeFix($run, 1);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        $imported = (string) file_get_contents($worktree.'/app/Example.php');
        $statusesBefore = FindingStatus::query()->where('run_id', $run->id)->count();
        $scopeBefore = (int) $run->fresh()->added_scope_paths_count;
        $intent = (string) $job->intent;

        // The worker died after the import but before it published the result: the
        // job is redelivered with its stored intent untouched.
        self::assertSame(1, DB::table('execution_jobs')->where('id', $job->id)->update([
            'state' => ExecutionJobState::PLANNED->value,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ]));
        $repeated = $this->executeFix($run, 1);

        self::assertSame($intent, (string) $repeated->intent, 'The redelivery rebound the step intent.');
        // Without a provider-side invocation binding the answer cannot be replayed,
        // so the repeated turn ends named instead of applying anything twice.
        self::assertSame('failed:reported_path_mismatch', $repeated->state->value.':'.(string) $repeated->failure_code);
        // Nothing was applied a second time.
        self::assertSame($imported, (string) file_get_contents($worktree.'/app/Example.php'));
        self::assertSame($statusesBefore, FindingStatus::query()->where('run_id', $run->id)->count());
        self::assertSame($scopeBefore, (int) $run->fresh()->added_scope_paths_count);
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::FIX->value)->count());
    }

    private function implementationSlot(Run $run): RunAgent
    {
        return RunAgent::query()->where('run_id', $run->id)->where('role', 'implementation')->sole();
    }

    private function projectIdentifier(Run $run): string
    {
        return (string) Project::query()->findOrFail($run->project_id)->project_identifier;
    }

    private function blocks(?Finding $finding, ?Run $run): bool
    {
        self::assertInstanceOf(Finding::class, $finding);
        self::assertInstanceOf(Run::class, $run);

        return $this->app->make(EffectiveFindingState::class)->blocks($finding, $run);
    }
}
