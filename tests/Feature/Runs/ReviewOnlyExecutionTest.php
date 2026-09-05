<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Auth\Models\User;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectException;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\GateKind;
use App\AI6\Runs\GateState;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-04, TC-06 and TC-10 of AI6-040: the review-only run executes prepare,
 * check, review and report on one ref-free review checkpoint, and every wait,
 * drift, limit and restart ends in its bound state without a second effect.
 */
final class ReviewOnlyExecutionTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;
    use BuildsReviewOnlyRunFixture;
    use BuildsRunWorkspaceGitFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        return $this->reviewOnlySelection($attentionUser);
    }

    public function test_a_review_only_run_prepares_checks_reviews_and_reports_on_one_bound_checkpoint(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-OK');
        $run = $this->prepareAndCheck($prepared);

        $workspace = (string) $run->worktree_path;
        $boundTree = (string) $run->checkpoint_tree_sha;
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        $result = ReviewResult::query()->where('run_id', $run->id)->sole();
        self::assertSame($boundTree, $result->checkpoint_tree_sha, 'The reviewer must see the bound review checkpoint.');
        self::assertSame((string) $run->review_workspace_hash, $result->workspace_tree_hash);
        $package = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::CONTEXT_PACKAGE->value)->sole();
        self::assertSame('quality_review:1:'.$result->slot_id, $package->redacted_metadata['stage'] ?? null);

        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REPORT));

        $report = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->sole();
        self::assertSame($boundTree, $report->redacted_metadata['checkpoint_tree_sha'] ?? null);
        self::assertDirectoryDoesNotExist($workspace, 'The disposable review checkpoint is removed after the run.');
        self::assertNull($run->fresh()?->run_branch);
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertTrue(
            $fresh->pending_status_operation_id !== null
                || ($fresh->state === RunState::WAITING && $fresh->wait_reason === WaitReason::MANUAL_REPORT),
            'The manual completion mode ends in its own bound saga or wait.',
        );
    }

    /** @param list<SecurityMeasure> $disabledMeasures */
    #[DataProvider('securityProfiles')]
    public function test_a_complete_review_only_workflow_keeps_reductions_visible_under_every_security_profile(
        SecurityProfile $profile,
        array $disabledMeasures,
    ): void {
        $this->requiresPosixEffectRuntime();
        $configuration = config('ai6.security');
        self::assertIsArray($configuration);
        $configuration['profile'] = $profile->value;
        $configuration['acknowledge_reduced_mode'] = $disabledMeasures === [] ? 'false' : 'true';
        foreach (SecurityMeasure::cases() as $measure) {
            $configuration['measures'][$measure->value] = in_array($measure, $disabledMeasures, true) ? 'false' : 'true';
        }
        $policy = (new SecurityPolicyFactory)->inspect($configuration);
        self::assertInstanceOf(SecurityPolicy::class, $policy);
        $this->app->instance(SecurityPolicy::class, $policy);

        $prepared = $this->preparedReviewOnlyRun('AI6-032-PROFILE-'.strtoupper($profile->value));
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REPORT));

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame($policy->hash(), $fresh->security_policy_hash);
        self::assertSame($profile, $policy->bannerData()->profile);
        self::assertSame($disabledMeasures, $policy->disabledMeasures());
        $this->assertFlaglessBoundaries($profile);
        self::assertSame(1, ReviewResult::query()->where('run_id', $run->id)->count());
        self::assertSame(1, CheckResultRecord::query()->where('run_id', $run->id)->count());
    }

    /** @param list<SecurityMeasure> $disabledMeasures */
    #[DataProvider('securityProfiles')]
    public function test_flagless_boundaries_reject_unsafe_input_under_every_security_profile(
        SecurityProfile $profile,
        array $disabledMeasures,
    ): void {
        $configuration = config('ai6.security');
        $configuration['profile'] = $profile->value;
        $configuration['acknowledge_reduced_mode'] = $disabledMeasures === [] ? 'false' : 'true';
        foreach (SecurityMeasure::cases() as $measure) {
            $configuration['measures'][$measure->value] = in_array($measure, $disabledMeasures, true) ? 'false' : 'true';
        }
        $policy = (new SecurityPolicyFactory)->inspect($configuration);
        self::assertInstanceOf(SecurityPolicy::class, $policy);
        $this->app->instance(SecurityPolicy::class, $policy);
        $this->assertFlaglessBoundaries($profile);
    }

    private function assertFlaglessBoundaries(SecurityProfile $profile): void
    {
        try {
            new ReviewSubject(
                ReviewSubjectKind::MANAGED_BRANCH,
                str_repeat('a', 64),
                str_repeat('b', 64),
                "refs/heads/main\nforged",
            );
            self::fail('The flagless Git ref validation accepted an injected ref under '.$profile->value.'.');
        } catch (ReviewSubjectException $exception) {
            self::assertSame('managed_branch_ref_invalid', $exception->reason);
            self::assertSame('The review subject is invalid.', $exception->getMessage());
        }
        try {
            $this->app->make(ManagedProjectPath::class)->repositoryDirectory('../outside');
            self::fail('The flagless path boundary accepted traversal under '.$profile->value.'.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The managed project identifier is invalid.', $exception->getMessage());
        }

        $redactor = $this->app->make(Redactor::class);
        $context = new RedactionContext('profile-test', $profile->value, 'flagless-boundaries');
        self::assertSame('password=[REDACTED:SECRET]', $redactor->redact('password=fixture-secret', $context)->text);
        try {
            $redactor->redact("password=fixture-secret\xFF", $context);
            self::fail('The flagless UTF-8 boundary accepted malformed input under '.$profile->value.'.');
        } catch (InvalidRedactionInputException $exception) {
            self::assertSame('Redaction input must be valid UTF-8.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{SecurityProfile, list<SecurityMeasure>}> */
    public static function securityProfiles(): iterable
    {
        yield 'strict' => [SecurityProfile::STRICT, []];
        yield 'custom' => [SecurityProfile::CUSTOM, [
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION,
            SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW,
        ]];
        yield 'development' => [SecurityProfile::DEVELOPMENT, [
            SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS,
        ]];
    }

    public function test_the_bound_checks_run_on_the_ref_free_review_checkpoint(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-CHECK', checkProfile: 'probe-review-only');
        $run = $prepared['run'];
        self::assertSame(
            ['probe-review-only'],
            ($run->config_snapshot ?? [])['values']['checks']['before_review'] ?? null,
            'The approval snapshot binds the profile the check step must run.',
        );

        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::CHECK));

        // The probe only exits 0 when it sees the reviewed content, so a green
        // result proves the check ran against the exported review checkpoint
        // and not against some other tree.
        $record = CheckResultRecord::query()->where('run_id', $run->id)->sole();
        self::assertSame('probe-review-only', $record->profile);
        self::assertSame(CheckPhase::BEFORE_REVIEW, $record->phase);
        self::assertSame(CheckResultState::SUCCEEDED, $record->state);

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        // Review readiness accepts the result only when its tree binding equals
        // the current binding of the review checkpoint (RunOrchestrator).
        self::assertSame('ready', $fresh->review_readiness_state);
        self::assertSame(RunPhase::REVIEW, $fresh->phase);
        self::assertNull($fresh->run_branch, 'A check never creates a run branch.');
        self::assertFileDoesNotExist((string) $fresh->worktree_path.'/.git');
    }

    public function test_review_findings_stay_visible_and_never_open_a_fix_phase(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-FINDINGS');
        $run = $this->prepareAndCheck($prepared);

        $this->bindReviewAdapter(AgentScenario::FINDINGS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));
        self::assertGreaterThan(0, Finding::query()->where('run_id', $run->id)->count());
        self::assertNotSame(RunPhase::FIX, $run->fresh()?->phase, 'A review-only run never enters the fix phase.');

        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REPORT));
        $report = $this->decodedReport($run);
        self::assertNotEmpty($report['findings']);
        self::assertSame(
            Finding::query()->where('run_id', $run->id)->count(),
            count($report['findings']),
            'Every finding of the run is projected into the bound report.',
        );
        self::assertContains(true, array_column($report['findings'], 'blocks'), 'An effectively blocking finding stays visible.');
    }

    public function test_a_blocked_completion_predicate_ends_the_report_step_visibly(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-GATE');
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        // A manual gate the ticket declares is still open when the report step
        // runs. `manual_gate` has neither producer nor resolver, so parking on
        // it would be inert; the step has to end visibly instead (plan §20).
        RunGate::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'gate_id' => 'MG-01',
            'kind' => GateKind::MANUAL, 'state' => GateState::OPEN,
            'blocks_candidate' => true, 'blocks_final_commit' => true, 'blocks_push' => true,
            'ticket_contract_sha256' => (string) $run->ticket_contract_sha256,
        ]);

        $job = $this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REPORT);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('report_completion_blocked', $job->failure_code);
        // The bound report stays published; only the completion saga is refused.
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count());
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::FAILED, $fresh->state);
        self::assertNull($fresh->wait_reason, 'No wait status is invented for a resolver that does not exist.');
        self::assertNotNull(
            RunEvent::query()->where('run_id', $run->id)
                ->where('event_key', 'like', 'report_completion_blocked:%')->first(),
            'The blocking reason is recorded under its own named key.',
        );
    }

    public function test_a_report_confirmation_that_cannot_be_opened_ends_the_step_visibly(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-NO-ATTENTION');
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        // The bound attention user is deactivated between approval and report,
        // so the manual confirmation can no longer be opened at all. That is a
        // refusal, not a transport error: no retry ever resolves it.
        self::assertSame(1, DB::table('users')->where('id', $prepared['attention']->getKey())
            ->update(['is_active' => false, 'updated_at' => now()]));

        $job = $this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REPORT);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('attention_user_unavailable', $job->failure_code);
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count(), 'The bound report stays published.');
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::FAILED, $fresh->state);
        self::assertNotNull(
            RunEvent::query()->where('run_id', $run->id)
                ->where('event_key', 'like', 'attention_user_unavailable:%')->first(),
        );
    }

    public function test_a_reviewer_question_parks_the_run_before_any_report(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-HUMAN');
        $run = $this->prepareAndCheck($prepared);

        $this->bindReviewAdapter(AgentScenario::HUMAN_REQUEST);
        $job = $this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW);
        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->state);

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::HUMAN_QUESTION, $fresh->wait_reason);
        self::assertSame(1, HumanRequest::query()->where('run_id', $run->id)->count());
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count());
        self::assertNull($this->app->make(RunOrchestrator::class)->planNextStep($fresh), 'A parked run plans no further step.');
    }

    public function test_an_exceeded_artifact_limit_parks_the_report_without_persisting_it(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-LIMIT');
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        // The approved artifact ceiling is far below the projected report.
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        $snapshot = $fresh->agent_profile_snapshot;
        self::assertIsArray($snapshot);
        $snapshot['limits']['max_artifact_bytes'] = 8;
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));

        $job = $this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REPORT);

        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count(), 'No oversized report is stored.');
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::RESOURCE_LIMIT, $fresh->wait_reason);
    }

    public function test_a_source_drift_between_binding_and_execution_blocks_visibly(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-DRIFT');
        $run = $prepared['run'];
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        $boundSource = (string) $run->review_subject_source_sha;

        // The managed branch moves after the source was bound and materialized.
        $this->managedReviewCommit($prepared['repository'], 'app/Example.php', "<?php\n\n// drifted\n", 'drifted change');
        self::assertNotSame($boundSource, trim($this->managedGit(['rev-parse', 'refs/heads/main'], $prepared['repository'])));

        $job = $this->executeReviewOnlyStep($run, ExecutionStepType::CHECK);

        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->state);
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $fresh->wait_reason);
        self::assertSame($boundSource, (string) $fresh->review_subject_source_sha, 'The run never adopts the drifted source.');
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)->count());
        self::assertNotNull(
            RunEvent::query()->where('run_id', $run->id)
                ->where('event_key', 'like', 'review_source_drift:%')->first(),
            'The drift is recorded with its own named reason.',
        );
    }

    public function test_a_worker_restart_redelivers_every_step_without_a_second_effect(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-RESTART');
        $run = $this->prepareAndCheck($prepared);
        $checkpoint = (string) $run->checkpoint_tree_sha;
        $workspace = (string) $run->worktree_path;
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        $artifacts = RunArtifact::query()->where('run_id', $run->id)->count();
        $results = ReviewResult::query()->where('run_id', $run->id)->count();
        self::assertGreaterThan(0, $artifacts, 'The redelivery must have a published effect to collide with.');
        self::assertGreaterThan(0, $results);

        // A worker that dies between the applied effect and its publication
        // leaves the job claimed with an expired lease — the one state
        // ExecuteRunStep really redelivers. Re-running a terminal job proves
        // nothing, because the dispatcher returns before the step body.
        foreach ([
            ExecutionStepType::REVIEW_PREPARE,
            ExecutionStepType::CHECK,
            ExecutionStepType::REVIEW,
        ] as $type) {
            $crashed = $this->crashedStep($run, $type);
            self::assertSame(ExecutionJobState::RUNNING, $crashed->state);
            $this->assertStepSucceeded($this->executeReviewOnlyStep($run->fresh() ?? $run, $type));
        }

        self::assertSame($artifacts, RunArtifact::query()->where('run_id', $run->id)->count(), 'A redelivery adds no artifact.');
        self::assertSame($results, ReviewResult::query()->where('run_id', $run->id)->count(), 'A redelivery adds no review result.');
        $fresh = $run->fresh() ?? $run;
        self::assertSame($checkpoint, $fresh->checkpoint_tree_sha, 'The bound checkpoint stays immutable.');
        self::assertSame($workspace, $fresh->worktree_path, 'A redelivery binds no second checkpoint directory.');
        self::assertNull($fresh->run_branch);

        // The report still runs exactly once on the redelivered state.
        $this->assertStepSucceeded($this->executeReviewOnlyStep($fresh, ExecutionStepType::REPORT));
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count());
        self::assertDirectoryDoesNotExist($workspace);
    }

    public function test_a_lost_publication_of_the_prepare_step_recovers_instead_of_failing_the_run(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-LOST-PUBLISH');
        $run = $prepared['run'];
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        self::assertSame(RunPhase::CHECK, $run->phase, 'The applied effect already advanced the phase.');

        // Only the publication was lost: the job is claimable again while the
        // run already carries the bound review subject.
        $this->crashedStep($run, ExecutionStepType::REVIEW_PREPARE);
        $job = $this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REVIEW_PREPARE);

        $this->assertStepSucceeded($job);
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertNotSame(RunState::FAILED, $fresh->state, 'A lost publication never fails the run.');
        self::assertSame($run->review_subject_source_sha, $fresh->review_subject_source_sha);
        self::assertSame($run->worktree_path, $fresh->worktree_path);
    }

    public function test_a_lost_review_checkpoint_is_rebuilt_from_its_unchanged_binding(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-LOST-EXPORT');
        $run = $prepared['run'];
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        $workspace = (string) $run->worktree_path;
        $workspaceHash = (string) $run->review_workspace_hash;

        // The bound checkpoint directory disappears without any drift of the
        // source: base, source, tree and diff stay exactly as bound.
        $prepared['paths']->removeRunWorktreeDirectory(
            (string) $prepared['project']->project_identifier,
            $run->id,
        );
        self::assertDirectoryDoesNotExist($workspace);

        $this->crashedStep($run, ExecutionStepType::REVIEW_PREPARE);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REVIEW_PREPARE));

        self::assertDirectoryExists($workspace, 'The lost checkpoint is rebuilt at its bound path.');
        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertSame($workspaceHash, $fresh->review_workspace_hash, 'The rebuilt tree hashes to the bound workspace hash.');
        self::assertNotSame(RunState::WAITING, $fresh->state, 'A rebuildable checkpoint is no base drift.');
        self::assertNull($fresh->run_branch);
    }

    public function test_the_automatic_completion_mode_needs_no_manual_report_wait(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun(
            'AI6-040-E2E-AUTO',
            completionMode: ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES,
        );
        self::assertSame(ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES, $prepared['run']->completion_mode);
        $this->markTicketInProgress($prepared, $prepared['run']);
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REPORT));

        $fresh = $run->fresh();
        self::assertInstanceOf(Run::class, $fresh);
        self::assertNotSame(WaitReason::MANUAL_REPORT, $fresh->wait_reason);
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count());
    }

    public function test_a_cancelled_review_only_run_publishes_no_report(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-E2E-CANCEL');
        $run = $this->prepareAndCheck($prepared);
        $this->bindReviewAdapter(AgentScenario::SUCCESS);
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW));

        // A bound cancellation fences the run before its report step.
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'state' => RunState::CANCELLED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));

        $job = $this->executeReviewOnlyStep($run->fresh() ?? $run, ExecutionStepType::REPORT);

        self::assertNotSame(ExecutionJobState::SUCCEEDED, $job->state, 'A cancelled run finishes no report step.');
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->count());
        self::assertSame(RunState::CANCELLED, $run->fresh()?->state);
    }

    /**
     * @param  array{run: Run, project: Project, repository: string, base: string, source: string}  $prepared
     */
    private function prepareAndCheck(array $prepared): Run
    {
        $run = $prepared['run'];
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        self::assertSame(RunType::REVIEW_ONLY, $run->run_type);
        self::assertNull($run->run_branch, 'The prepare step never creates a run branch.');
        self::assertIsString($run->worktree_path);
        self::assertFileDoesNotExist($run->worktree_path.'/.git');

        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::CHECK));
        $run = $run->fresh() ?? $run;
        self::assertSame('ready', $run->review_readiness_state, 'The review-only readiness binds the normalized checkpoint.');
        self::assertSame(RunPhase::REVIEW, $run->phase);

        return $run;
    }

    private function bindReviewAdapter(AgentScenario $scenario): FakeAgentAdapter
    {
        $adapter = new FakeAgentAdapter($scenario);
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

    /** @return array<string, mixed> */
    private function decodedReport(Run $run): array
    {
        $report = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->sole();
        $path = $this->app->make(RunArtifactRoot::class)->path.DIRECTORY_SEPARATOR.$report->storage_reference;
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
