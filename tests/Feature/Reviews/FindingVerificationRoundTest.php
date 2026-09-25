<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\IsolatedTreeExport;
use App\AI6\Git\ReviewCheckpointVerifier;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\FindingVerificationRound;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Git\AssertsGitObjectGuards;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;
use Tests\Fixtures\Agents\MissingAnswerAdapter;

final class FindingVerificationRoundTest extends TicketUiTestCase
{
    use AssertsGitObjectGuards;
    use BuildsReviewRoundFixture;

    private bool $nativeMissingAnswer = false;

    protected function usesNativeProviderMailbox(): bool
    {
        return $this->nativeMissingAnswer;
    }

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    /** @return iterable<string, array{string}> */
    public static function earlyVerificationFailures(): iterable
    {
        yield 'binding error' => ['binding_error'];
        yield 'workspace error' => ['workspace_error'];
        yield 'export failure' => ['export_failure'];
    }

    #[DataProvider('earlyVerificationFailures')]
    public function test_early_failures_store_no_checker_hash_and_a_subsequent_run_computes_it(string $outcome): void
    {
        $guards = ['findings_insert_guard', 'review_results_insert_guard'];
        $this->observeGitObjectGuards($guards);
        Mail::fake();
        config(['ai6.agent_profiles.grok-cli-review.roles' => ['quality_review']]);
        $prepared = $this->preparedReviewRun('AI6-051-VERIFY');
        $run = $prepared['run'];
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($run)->state);
        $this->assertGitObjectGuardsObserved($guards);
        $file = $prepared['worktree'].'/app/Example.php';
        $original = file_get_contents($file);
        self::assertIsString($original);
        $root = config('ai6.execution_mailboxes.agent_root');
        $exporter = app(IsolatedTreeExport::class);
        if ($outcome === 'binding_error') {
            self::assertNotFalse(file_put_contents($file, $original."\n// dirty\n"));
        } elseif ($outcome === 'export_failure') {
            $this->app->instance(IsolatedTreeExport::class, new class implements IsolatedTreeExport
            {
                public function export(string $source, string $destination, bool $writable = false): void
                {
                    throw new \RuntimeException('Injected export failure.');
                }
            });
            $this->app->forgetInstance(FindingVerificationRound::class);
        } else {
            config(['ai6.execution_mailboxes.agent_root' => '']);
        }
        $failed = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'verify')->sole();
        (new ExecuteRunStep($failed->id))->handle(app(RunOrchestrator::class), verifications: app(FindingVerificationRound::class));
        self::assertSame(ExecutionJobState::FAILED, $failed->refresh()->state);
        $results = ReviewResult::query()->where('run_id', $run->id)->where('role', 'finding_verification')->get();
        self::assertNotEmpty($results);
        foreach ($results as $result) {
            self::assertSame($outcome === 'export_failure' ? 'workspace_error' : $outcome, $result->invocation_outcome->value);
            self::assertNull($result->workspace_tree_hash);
        }
        file_put_contents($file, $original);
        $this->gitOutput(['status', '--porcelain=v2'], $prepared['worktree']);
        config(['ai6.execution_mailboxes.agent_root' => $root]);
        $this->app->instance(IsolatedTreeExport::class, $exporter);
        // Binding/workspace errors are terminal in the existing workflow; a new
        // authorized run must calculate its own hash instead of reusing one.
        $this->removeRunWorkspaceFixture();
        $this->removeImplementationFixture();
        $this->app->forgetInstance(ReviewCheckpointVerifier::class);
        $this->app->forgetInstance(FindingVerificationRound::class);
        $subsequent = $this->preparedReviewRun('AI6-051-VERIFY-RETRY');
        $run = $subsequent['run'];
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($run)->state);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeVerification($run->fresh())->state);
        $result = ReviewResult::query()->where('run_id', $run->id)->where('role', 'finding_verification')
            ->where('invocation_outcome', 'valid_result')->sole();
        self::assertSame(64, strlen($result->workspace_tree_hash));
        self::assertNotSame($result->checkpoint_tree_sha, $result->workspace_tree_hash);
        self::assertSame(ReviewResult::query()->where('run_id', $run->id)->where('role', 'quality_review')->firstOrFail()->workspace_tree_hash, $result->workspace_tree_hash);
    }

    public function test_expired_verifier_evidence_parks_without_recording_a_failed_attempt(): void
    {
        // This orchestration proof uses the fixture's Copilot verifier on every
        // OS; Grok's independent session-link tests remain Linux-only.
        config(['ai6.agent_profiles.grok-cli-review.roles' => ['quality_review']]);
        $prepared = $this->preparedReviewRun('AI6-035-VERIFY-RECHECK');
        $run = $prepared['run'];
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($run)->state);
        $document = app(ProviderCapabilityReport::class)->read('github_copilot_cli');
        self::assertNotNull($document);
        config(['ai6.runtime_role' => 'agent']);
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $document['rows'], time() - 301, $document['boot_id']));
        config(['ai6.runtime_role' => 'worker']);
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'verify')->sole();
        (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), verifications: app(FindingVerificationRound::class));
        self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
        self::assertSame(0, $job->attempts);
        self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/requests/*.json'));
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)->where('role', 'finding_verification')->count());
        $this->seedProviderReports();
        self::assertTrue(app(RunOrchestrator::class)->resumeStep($job));
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeVerification($run)->state);
        self::assertSame('valid_result', ReviewResult::query()->where('run_id', $run->id)->where('role', 'finding_verification')->sole()->invocation_outcome->value);
    }

    /** AI6-047 TC-09: a missing mailbox answer is invalid_json, distinct from provider_error. */
    public function test_a_missing_mailbox_answer_is_invalid_json_and_distinct_from_provider_error(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The agent credential boundary requires real Linux namespaces.');
        }
        $this->nativeMissingAnswer = true;
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-047-VERIFY-INVALIDJSON');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $adapter = new MissingAnswerAdapter;
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);

        $verification = $this->executeVerification($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        $result = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->latest('attempt')->firstOrFail();
        self::assertSame('invalid_json', $result->invocation_outcome->value);
        self::assertNotSame('provider_error', $result->invocation_outcome->value);
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        self::assertSame('invalid_json', $request->kind);
    }

    /** AI6-047 TC-09: an answer over the artifact-size budget reaches AgentExecutionLimitReached. */
    public function test_an_oversized_answer_opens_a_resource_limit_request(): void
    {
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-047-VERIFY-LIMIT');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);
        $this->reviewAdapter([]);
        $this->app->forgetInstance(FindingVerificationRound::class);
        $snapshot = $prepared['run']->fresh()->agent_profile_snapshot;
        $snapshot['limits']['max_artifact_bytes'] = 1;
        DB::table('runs')->where('id', $prepared['run']->id)->update([
            'agent_profile_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
        ]);

        $verification = $this->executeVerification($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        self::assertSame('resource_limit', $prepared['run']->fresh()->wait_reason?->value);
        self::assertTrue(HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('kind', 'resource_limit')->where('resolution_state', 'open')->exists());
        self::assertSame(0, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->count());
    }

    public function test_a_contradicting_verifier_result_is_persisted_as_advisory_evidence_only(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Grok session links require the Linux runtime.');
        }
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-ADVISORY');
        $boundPool = $prepared['run']->agent_profile_snapshot['verifier_candidates'] ?? null;
        self::assertIsArray($boundPool);
        self::assertNotEmpty($boundPool);
        self::assertIsArray($prepared['run']->prompt_snapshot['finding_verification_snapshot'] ?? null);
        foreach ($boundPool as $candidate) {
            self::assertIsString($candidate['provider_profile'] ?? null);
            self::assertIsString($candidate['runtime_profile_id'] ?? null);
            self::assertContains('finding_verification_capability_available', $candidate['selection_reasons'] ?? []);
            self::assertArrayHasKey($candidate['provider_profile'], $prepared['run']->instruction_snapshot);
            self::assertArrayHasKey($candidate['runtime_profile_id'], $prepared['run']->runtime_profile_snapshot);
        }
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $finding = Finding::query()->where('run_id', $prepared['run']->id)->sole();
        $original = $finding->getAttributes();
        $adapter = new FakeAgentAdapter(AgentScenario::REJECTS_FINDING);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);
        config(['ai6.agent_profiles' => []]);

        $verification = $this->executeVerification($prepared['run']);

        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        $result = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->sole();
        self::assertSame('valid_result', $result->invocation_outcome->value);
        self::assertSame('contradicted', $result->verification_assessment);
        self::assertSame('not_applicable', $result->verification_recommendation);
        self::assertSame($finding->id, $result->original_finding_id);
        self::assertSame($original, $finding->fresh()->getAttributes());
        try {
            $result->forceFill(['verification_evidence' => 'rewritten'])->save();
            self::fail('An immutable verifier result was updated.');
        } catch (QueryException) {
            self::assertSame('Deterministische unabhängige Verifierevidenz.', $result->fresh()->verification_evidence);
        }
        self::assertSame($boundPool, $prepared['run']->fresh()->agent_profile_snapshot['verifier_candidates']);
        self::assertNotSame(
            ReviewResult::query()->findOrFail($finding->review_result_id)->session_id,
            $result->session_id,
        );
        $package = RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->where('kind', RunArtifactKind::CONTEXT_PACKAGE->value)->get()
            ->first(static fn (RunArtifact $artifact): bool => str_starts_with(
                (string) ($artifact->redacted_metadata['stage'] ?? ''),
                'finding_verification:',
            ));
        self::assertInstanceOf(RunArtifact::class, $package);
        $packagePath = rtrim($this->app->make(RunArtifactRoot::class)->path, '/\\')
            .DIRECTORY_SEPARATOR.$package->storage_reference;
        $payload = json_decode((string) file_get_contents($packagePath), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($finding->id, $payload['finding']['id'] ?? null);
        self::assertNotEmpty($payload['relevant_code'] ?? []);
        foreach (['run_id', 'ticket_blob_sha', 'checkpoint_tree_sha', 'diff_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'provider_runtime_profile_hash', 'security_policy_hash'] as $binding) {
            self::assertNotEmpty($payload[$binding] ?? null, $binding);
        }
        $viewer = ProjectMembership::query()->where('project_id', $prepared['run']->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $this->actingAs($viewer)->get(route('projects.runs.show', [
            $prepared['run']->project()->firstOrFail(),
            $prepared['run']->id,
        ]))->assertOk()
            ->assertSee('Advisory Verifierevidenz')
            ->assertSee('Deterministische unabhängige Verifierevidenz.')
            ->assertSee('data-verification-evidence', false)
            ->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        self::assertDatabaseCount('finding_dispositions', 0);
        self::assertTrue($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $prepared['run']->fresh()));
        self::assertDatabaseCount('human_requests', 1);

        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        self::assertContains('switch_profile', $request->allowed_effects);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $viewer,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'switch_profile',
            $this->authorization($request, $viewer, 'switch_profile'),
        );
        $confirmed = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->instance(FakeAgentAdapter::class, $confirmed);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $confirmed);
        $this->app->forgetInstance(FindingVerificationRound::class);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeVerification($prepared['run']->fresh())->state);
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->where('original_finding_id', $finding->id)
            ->where('invocation_outcome', 'valid_result')->count());
        self::assertDatabaseCount('finding_dispositions', 0);
        self::assertTrue($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $prepared['run']->fresh()));
    }

    public function test_no_independent_approved_candidate_opens_a_request_without_a_verifier_turn(): void
    {
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-INDEPENDENCE', enableIndependentFallback: false);
        $this->reviewAdapter([$this->reviewSlotIds[1] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $run = $prepared['run']->fresh();
        $adapter = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);

        $verification = $this->executeVerification($run->fresh());

        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        self::assertSame(0, $adapter->turnCount);
        self::assertSame('verification_independence', HumanRequest::query()->where('run_id', $run->id)->sole()->kind);
        self::assertSame(0, ReviewResult::query()->where('run_id', $run->id)->where('role', 'finding_verification')->count());
        self::assertSame(0, FindingDisposition::query()->where('run_id', $run->id)->count());
    }

    public function test_one_verifier_result_is_shared_by_all_members_of_a_duplicate_group(): void
    {
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-GROUPED-VERIFICATION');
        $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $findings = Finding::query()->where('run_id', $prepared['run']->id)->get();
        self::assertCount(2, $findings);
        self::assertSame(1, $findings->pluck('duplicate_group')->unique()->count());

        $adapter = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeVerification($prepared['run'])->state);

        $result = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->sole();
        self::assertSame(1, $adapter->turnCount);
        self::assertNull($result->original_finding_id);
        self::assertSame($findings->firstOrFail()->duplicate_group, $result->original_duplicate_group);
        self::assertNotContains($result->provider_profile, $findings->pluck('provider_profile')->unique()->all());

        $viewer = ProjectMembership::query()->where('project_id', $prepared['run']->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $response = $this->actingAs($viewer)->get(route('projects.runs.show', [
            $prepared['run']->project()->firstOrFail(),
            $prepared['run']->id,
        ]))->assertOk();
        self::assertSame(2, substr_count($response->getContent(), 'Deterministische unabhängige Verifierevidenz.'));
    }

    public function test_an_inconclusive_verifier_result_parks_once_without_changing_the_finding(): void
    {
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-INCONCLUSIVE');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);
        $finding = Finding::query()->where('run_id', $prepared['run']->id)->sole();

        $adapter = new FakeAgentAdapter(AgentScenario::VERIFICATION_INCONCLUSIVE);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);

        self::assertSame(ExecutionJobState::WAITING, $this->executeVerification($prepared['run'])->state);
        self::assertSame('inconclusive', ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->sole()->verification_assessment);
        self::assertSame(1, HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('resolution_state', 'open')->count());
        self::assertTrue($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $prepared['run']->fresh()));

        // Re-delivery cannot create a second concurrent blocking request.
        self::assertSame(ExecutionJobState::WAITING, $this->executeVerification($prepared['run']->fresh())->state);
        self::assertSame(1, HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('resolution_state', 'open')->count());
    }

    public function test_a_verifier_human_request_is_persisted_and_visible(): void
    {
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-VERIFIER-HUMAN');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $adapter = new FakeAgentAdapter(AgentScenario::HUMAN_REQUEST);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);

        self::assertSame(ExecutionJobState::WAITING, $this->executeVerification($prepared['run'])->state);
        self::assertSame('needs_human', ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->sole()->invocation_outcome->value);
        self::assertSame('clarification', HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('resolution_state', 'open')->sole()->kind);
    }

    public function test_invalid_verifier_schema_uses_the_bounded_retry_and_existing_human_request_path(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Grok session links require the Linux runtime.');
        }
        Mail::fake();
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-043-INVALID-SCHEMA');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReview($prepared['run'])->state);

        $adapter = new FakeAgentAdapter(AgentScenario::INVALID_JSON);
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $adapter);
        $this->app->forgetInstance(FindingVerificationRound::class);

        $verification = $this->executeVerification($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::WAITING, $verification->state);
        self::assertSame((int) config('ai6.run_steps.max_attempts'), $adapter->turnCount);
        self::assertSame($adapter->turnCount, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->where('invocation_outcome', 'invalid_json')->count());
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        self::assertSame('invalid_json', $request->kind);
        self::assertContains('new_turn', $request->allowed_effects);
        self::assertContains('switch_profile', $request->allowed_effects);

        $old = RunAgent::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->where('is_active', true)->sole();
        $actor = ProjectMembership::query()->where('project_id', $prepared['run']->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $actor,
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'switch_profile',
            $this->authorization($request, $actor, 'switch_profile'),
        );
        $revision = RunAgent::query()->where('run_id', $prepared['run']->id)
            ->where('role', 'finding_verification')->where('is_active', true)->sole();
        self::assertFalse($old->fresh()->is_active);
        self::assertSame($old->slot_revision + 1, $revision->slot_revision);
        self::assertNotSame($old->provider_profile, $revision->provider_profile);
        self::assertNotSame($old->session_id, $revision->session_id);

        $success = new FakeAgentAdapter(AgentScenario::SUCCESS);
        $this->app->instance(FakeAgentAdapter::class, $success);
        $this->app->bind(AgentAdapter::class, static fn (): AgentAdapter => $success);
        $this->app->forgetInstance(FindingVerificationRound::class);
        $resumed = $this->executeVerification($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::SUCCEEDED, $resumed->state);
        self::assertSame(1, $success->turnCount);
    }

    private function authorization(HumanRequest $request, User $actor, string $effect): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('finding-verification-'.bin2hex(random_bytes(8)));
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

    private function executeVerification(Run $run): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::VERIFY->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            verifications: $this->app->make(FindingVerificationRound::class),
        );
        AgentMailboxFixture::drain($job, function () use ($job): void {
            (new ExecuteRunStep($job->id))->handle(
                $this->app->make(RunOrchestrator::class),
                verifications: $this->app->make(FindingVerificationRound::class),
            );
        });

        return $job->fresh() ?? $job;
    }
}
