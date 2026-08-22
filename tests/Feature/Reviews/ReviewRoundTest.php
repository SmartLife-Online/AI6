<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\IsolatedTreeExport;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\WorktreeGitMetadataPaths;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReasonRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ReviewRoundTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    /** TC-01, TC-06 and TC-07. */
    public function test_two_reviewers_run_serially_with_separate_bindings_sessions_and_homes(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC01');
        $agentRoot = config('ai6.execution_mailboxes.agent_root');
        self::assertIsString($agentRoot);
        $foreignHome = $agentRoot.DIRECTORY_SEPARATOR.'foreign-home';
        self::assertTrue(mkdir($foreignHome, 0700));
        $foreignSecret = $foreignHome.DIRECTORY_SEPARATOR.'auth-token';
        self::assertNotFalse(file_put_contents($foreignSecret, 'foreign-review-credential'));
        $parentInstruction = $agentRoot.DIRECTORY_SEPARATOR.'AGENTS.md';
        self::assertNotFalse(file_put_contents($parentInstruction, 'Nicht freigegebene Elterninstruktion.'));
        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ], [$foreignHome, $foreignSecret]);

        $job = $this->executeReview($prepared['run']);
        self::assertTrue(unlink($foreignSecret));
        self::assertTrue(rmdir($foreignHome));
        self::assertTrue(unlink($parentInstruction));

        self::assertSame(
            ExecutionJobState::SUCCEEDED,
            $job->state,
            json_encode(ReviewResult::query()->where('run_id', $prepared['run']->id)
                ->get(['slot_id', 'invocation_outcome', 'failure_code'])->toArray(), JSON_UNESCAPED_SLASHES),
        );
        $results = ReviewResult::query()->where('run_id', $prepared['run']->id)->orderBy('id')->get();
        self::assertCount(2, $results);
        self::assertEqualsCanonicalizing($this->reviewSlotIds, $results->pluck('slot_id')->all());
        self::assertSame([ReviewInvocationOutcome::VALID_RESULT], $results->pluck('invocation_outcome')->unique()->values()->all());
        self::assertCount(1, $results->pluck('checkpoint_commit_sha')->unique());
        self::assertCount(1, $results->pluck('checkpoint_tree_sha')->unique());
        self::assertCount(1, $results->pluck('diff_hash')->unique());
        self::assertCount(1, $results->pluck('approval_snapshot_hash')->unique());
        self::assertCount(1, $results->pluck('workspace_tree_hash')->unique());
        self::assertCount(2, $results->pluck('slot_prompt_hash')->unique());
        self::assertCount(2, $results->pluck('slot_instruction_hash')->unique());
        self::assertCount(2, $results->pluck('slot_runtime_profile_hash')->unique());
        self::assertCount(2, $results->pluck('session_id')->unique());
        $run = $prepared['run']->fresh();
        $reviewers = $run->agent_profile_snapshot['reviewers'] ?? null;
        self::assertIsArray($reviewers);
        foreach ($results as $result) {
            $reviewer = null;
            foreach ($reviewers as $candidate) {
                self::assertIsArray($candidate);
                if (($candidate['id'] ?? null) === $result->slot_id) {
                    $reviewer = $candidate;
                    break;
                }
            }
            self::assertIsArray($reviewer);
            self::assertSame(
                $run->prompt_snapshot['review_profile_snapshots'][$reviewer['prompt_profile_id']]['prompt_snapshot_hash'],
                $result->slot_prompt_hash,
            );
            self::assertSame(
                $run->instruction_snapshot[$reviewer['provider_profile']]['instruction_snapshot_hash'],
                $result->slot_instruction_hash,
            );
            self::assertSame(
                $run->runtime_profile_snapshot[$reviewer['runtime_profile_id']]['hash'],
                $result->slot_runtime_profile_hash,
            );
        }
        self::assertSame($this->reviewSlotIds, array_column($adapter->contextPackages, 'slot_id'));
        self::assertCount(2, $adapter->contexts);
        self::assertCount(2, $adapter->turnResults);
        $firstResult = json_decode($adapter->turnResults[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($firstResult);
        $firstSummary = $firstResult['summary'] ?? null;
        $firstFinding = $firstResult['findings'][0]['evidence'] ?? null;
        self::assertIsString($firstSummary);
        self::assertIsString($firstFinding);
        $secondContext = serialize($adapter->contexts[1]);
        $secondPrompt = $adapter->renderedPrompts[1];
        foreach ([$firstSummary, $firstFinding, $adapter->turnResults[0]] as $foreignReviewBytes) {
            self::assertStringNotContainsString($foreignReviewBytes, $secondContext);
            self::assertStringNotContainsString($foreignReviewBytes, $secondPrompt);
        }
        self::assertSame('', $adapter->contexts[1]->actualDiff);

        $gitMetadataPaths = $this->app->make(WorktreeGitMetadataPaths::class)->resolve($prepared['worktree']);
        self::assertTrue((bool) array_filter(
            $gitMetadataPaths,
            static fn (string $path): bool => basename($path) === 'refs',
        ));
        self::assertCount(2, $adapter->accessProbeHistory);
        foreach ($adapter->accessProbeHistory as $probes) {
            self::assertSame('denied', $probes['write:existing'] ?? null);
            foreach (['.git', '.git/refs', '.git/hooks', '.git/commondir', '../.git'] as $metadata) {
                self::assertSame('missing', $probes['workspace:'.$metadata] ?? null, $metadata);
            }
            foreach ([...$gitMetadataPaths, $foreignHome, $foreignSecret] as $unreachable) {
                self::assertSame('denied', $probes['path:'.$unreachable] ?? null, $unreachable);
            }
            foreach (range(0, 3) as $level) {
                self::assertSame('missing', $probes['instruction-parent:'.$level] ?? null, 'instruction parent '.$level);
            }
        }
        self::assertSame([], $this->directoryEntries(config('ai6.execution_mailboxes.agent_root')));
        self::assertSame([], $this->directoryEntries(config('ai6.execution_mailboxes.agent_output_root')));
        self::assertSame('', $this->gitOutput(['status', '--porcelain=v2'], $prepared['worktree']));
    }

    /** TC-03 POSIX boundary: directory mode prevents creating a new workspace file. */
    public function test_posix_review_workspace_refuses_a_new_file(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Der Verzeichnis-Schreibschutz benötigt die produktive POSIX-/Linux-Laufzeit.');
        }
        $prepared = $this->preparedReviewRun('AI6-023-TC03-POSIX');
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertCount(2, $adapter->accessProbeHistory);
        foreach ($adapter->accessProbeHistory as $probes) {
            self::assertSame('denied', $probes['write:new'] ?? null);
        }
    }

    /** TC-02, TC-08 and TC-10. */
    public function test_a_failed_slot_does_not_skip_the_later_slot_and_redelivery_is_idempotent(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC02');
        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::PROVIDER_ERROR,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('review_slot_failed', $job->failure_code);
        self::assertSame(RunState::FAILED, $prepared['run']->fresh()->state);
        self::assertSame(2, $adapter->turnCount);
        self::assertEqualsCanonicalizing(
            [ReviewInvocationOutcome::PROVIDER_ERROR, ReviewInvocationOutcome::VALID_RESULT],
            ReviewResult::query()->where('run_id', $prepared['run']->id)->pluck('invocation_outcome')->all(),
        );

        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), reviews: $this->app->make(ReviewRound::class));
        self::assertSame(2, $adapter->turnCount);
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)->count());
    }

    /** TC-01 negative: a second export with other bytes is rejected before its provider call. */
    public function test_a_changed_second_export_is_a_named_workspace_binding_error(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC01-MISMATCH');
        $delegate = $this->app->make(IsolatedTreeExporter::class);
        $this->app->instance(IsolatedTreeExport::class, new class($delegate) implements IsolatedTreeExport
        {
            private int $exports = 0;

            public function __construct(private readonly IsolatedTreeExporter $delegate) {}

            public function export(string $source, string $destination, bool $writable = false): void
            {
                $this->delegate->export($source, $destination, $writable);
                $this->exports++;
                if ($this->exports !== 2) {
                    return;
                }
                $marker = $destination.DIRECTORY_SEPARATOR.'foreign-review-tree';
                if (! chmod($destination, 0700)
                    || file_put_contents($marker, 'drift') === false
                    || ! chmod($marker, 0444)
                    || ! chmod($destination, 0555)) {
                    throw new \RuntimeException('The mismatching review export could not be prepared.');
                }
            }
        });
        $this->app->forgetInstance(ReviewRound::class);
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('review_slot_failed', $job->failure_code);
        self::assertSame(1, $adapter->turnCount);
        $second = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[1])->sole();
        self::assertSame(ReviewInvocationOutcome::WORKSPACE_ERROR, $second->invocation_outcome);
        self::assertSame('review_workspace_binding_mismatch', $second->failure_code);
    }

    /** TC-08: the database, not only the service, rejects a rewrite. */
    public function test_review_results_are_immutable_and_only_one_valid_result_exists_per_slot(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC08');
        $this->reviewAdapter([]);
        $this->executeReview($prepared['run']);
        $result = ReviewResult::query()->where('run_id', $prepared['run']->id)->firstOrFail();

        try {
            DB::table('review_results')->where('id', $result->id)->update(['result_status' => 'findings_to_fix']);
            self::fail('The immutable review-result guard accepted an update.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        $slots = RunAgent::query()->where('run_id', $prepared['run']->id)
            ->where('role', AgentRole::QUALITY_REVIEW->value)->orderBy('id')->get();
        self::assertCount(2, $slots);
        try {
            DB::table('run_agents')->where('id', $slots[1]->id)->update(['session_id' => $slots[0]->session_id]);
            self::fail('The cross-slot session guard accepted a shared session.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('UNIQUE', strtoupper($exception->getMessage()));
        }

        $duplicate = $result->getAttributes();
        $duplicate['id'] = (string) Str::uuid();
        $duplicate['attempt'] = 2;
        unset($duplicate['created_at'], $duplicate['updated_at']);
        $this->expectException(\Throwable::class);
        DB::table('review_results')->insert($duplicate);
    }

    /** TC-06 and TC-09: only the asking slot resumes its own session. */
    public function test_a_human_answer_resumes_only_the_asking_slot(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-023-TC09');
        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::SUCCESS,
            $this->reviewSlotIds[1] => [AgentScenario::HUMAN_REQUEST, AgentScenario::SUCCESS],
        ]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::WAITING, $job->state);
        self::assertSame(RunState::WAITING, $prepared['run']->fresh()->state);
        self::assertSame(2, $adapter->turnCount);
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        self::assertSame($this->reviewSlotIds[1], $request->bound_agent_slot);
        self::assertSame($job->idempotency_key, $request->bound_step_key);
        $session = RunAgent::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[1])->value('session_id');
        self::assertIsString($session);

        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $request->attentionUser()->firstOrFail(),
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'a',
        );
        $resumed = $job->fresh();
        self::assertNotNull($resumed);
        self::assertSame(ExecutionJobState::PLANNED, $resumed->state);

        $completed = $this->executeReview($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::SUCCEEDED, $completed->state, (string) $completed->failure_code);
        self::assertSame(3, $adapter->turnCount);
        self::assertSame(
            [$this->reviewSlotIds[0], $this->reviewSlotIds[1], $this->reviewSlotIds[1]],
            array_column($adapter->contextPackages, 'slot_id'),
        );
        self::assertSame([1, 1, 2], array_column($adapter->contextPackages, 'attempt'));
        self::assertSame(1, RunAgent::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[1])->where('session_id', $session)->count());
        self::assertSame(1, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[0])->count());
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[1])->count());
    }

    /** TC-10: current configuration cannot replace the snapshot, and invalid JSON stays distinct. */
    public function test_invalid_json_is_named_without_changing_slots_or_wait_reasons(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC10');
        $expectedSlots = $this->reviewSlotIds;
        $registeredReasons = $this->app->make(WaitReasonRegistry::class)->registeredReasons();
        config(['ai6.agent_profiles' => []]);
        $adapter = $this->reviewAdapter([
            $expectedSlots[0] => AgentScenario::INVALID_JSON,
            $expectedSlots[1] => AgentScenario::SUCCESS,
        ]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('review_slot_failed', $job->failure_code);
        self::assertSame(2, $adapter->turnCount);
        self::assertEqualsCanonicalizing(
            $expectedSlots,
            RunAgent::query()->where('run_id', $prepared['run']->id)
                ->where('role', AgentRole::QUALITY_REVIEW->value)->pluck('slot_id')->all(),
        );
        $invalid = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $expectedSlots[0])->sole();
        self::assertSame(ReviewInvocationOutcome::INVALID_JSON, $invalid->invocation_outcome);
        self::assertSame('invalid_json', $invalid->failure_code);
        self::assertNull($invalid->result_status);
        self::assertSame(0, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $expectedSlots[0])
            ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT->value)->count());
        self::assertSame($registeredReasons, $this->app->make(WaitReasonRegistry::class)->registeredReasons());
        self::assertSame(
            ['human_question', 'resource_limit', 'scope_approval', 'contract_change', 'check_failure'],
            $registeredReasons,
        );
    }

    /** TC-05: a host instruction candidate is refused with its typed reason before either provider call. */
    public function test_instruction_drift_stops_reviewers_before_provider_invocation(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC05I');
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [new InstructionCandidate(
                    'agents_md',
                    InstructionCandidateOrigin::HOST,
                    true,
                    InstructionFileType::REGULAR,
                    'AGENTS.md',
                    str_repeat('a', 40),
                    'Nicht freigegebene Hostinstruktion.',
                )];
            }
        });
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame(0, $adapter->turnCount);
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('invocation_outcome', ReviewInvocationOutcome::BINDING_ERROR->value)
            ->where('failure_code', 'instruction_host_source_forbidden')->count());
    }

    /** TC-05: a blob changed after approval fails the bound hash comparison before the provider. */
    public function test_changed_instruction_blob_stops_reviewers_before_provider_invocation(): void
    {
        $approved = new InstructionCandidate(
            'agents_md',
            InstructionCandidateOrigin::REPOSITORY,
            true,
            InstructionFileType::REGULAR,
            'AGENTS.md',
            str_repeat('a', 40),
            'Freigegebene Projektinstruktion.',
        );
        $prepared = $this->preparedReviewRun('AI6-023-TC05-BLOB', [$approved]);
        $changed = new InstructionCandidate(
            'agents_md',
            InstructionCandidateOrigin::REPOSITORY,
            true,
            InstructionFileType::REGULAR,
            'AGENTS.md',
            str_repeat('b', 40),
            'Nach der Freigabe geänderte Projektinstruktion.',
        );
        $this->app->instance(InstructionCandidateSource::class, new class($changed) implements InstructionCandidateSource
        {
            public function __construct(private readonly InstructionCandidate $candidate) {}

            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [$this->candidate];
            }
        });
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame(0, $adapter->turnCount);
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('invocation_outcome', ReviewInvocationOutcome::BINDING_ERROR->value)
            ->where('failure_code', 'instruction_binding_drift')->count());
    }

    /** TC-05: native discovery exposes exactly the bound bytes and no parent instructions. */
    public function test_bound_instruction_bytes_are_visible_only_at_the_workspace_discovery_path(): void
    {
        $candidate = new InstructionCandidate(
            'agents_md',
            InstructionCandidateOrigin::REPOSITORY,
            true,
            InstructionFileType::REGULAR,
            'AGENTS.md',
            str_repeat('a', 40),
            "Freigegebene Projektinstruktion.\n",
        );
        $prepared = $this->preparedReviewRun('AI6-023-TC05-NATIVE', [$candidate]);
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertCount(2, $adapter->contexts);
        self::assertCount(2, $adapter->accessProbeHistory);
        foreach ($adapter->contexts as $index => $context) {
            self::assertCount(1, $context->instructionSnapshot->entries);
            $entry = $context->instructionSnapshot->entries[0];
            $probes = $adapter->accessProbeHistory[$index];
            self::assertSame('sha256:'.$entry->contentSha256, $probes['instruction-parent:0'] ?? null);
            foreach (range(1, 3) as $level) {
                self::assertSame('missing', $probes['instruction-parent:'.$level] ?? null, 'instruction parent '.$level);
            }
        }
    }

    public function test_instruction_source_infrastructure_failure_is_not_mislabeled_as_drift(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC05-INFRA');
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                throw new \RuntimeException('instruction-source-infrastructure-failure');
            }
        });
        $this->app->forgetInstance(InstructionBindingVerifier::class);

        $reviewer = $prepared['run']->agent_profile_snapshot['reviewers'][0] ?? null;
        self::assertIsArray($reviewer);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instruction-source-infrastructure-failure');
        $this->app->make(InstructionBindingVerifier::class)->driftCodeForProfile(
            $prepared['run'],
            $reviewer['provider_profile'],
            $reviewer['runtime_profile_id'],
        );
    }

    /** TC-05: a changed server runtime profile has a distinct pre-provider failure. */
    public function test_runtime_profile_drift_is_distinct_from_instruction_drift(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC05R');
        $runtimeProfiles = config('ai6.provider_runtime_profiles');
        $runtimeProfiles['fake-v1']['adapter_flags'] = ['mode' => 'changed-after-approval'];
        config(['ai6.provider_runtime_profiles' => $runtimeProfiles]);
        foreach ([ProviderRuntimeProfileRegistry::class, InstructionBindingVerifier::class, ReviewRound::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame(1, $adapter->turnCount);
        $result = ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[0])->sole();
        self::assertSame(ReviewInvocationOutcome::BINDING_ERROR, $result->invocation_outcome);
        self::assertSame('runtime_profile_drift', $result->failure_code);
    }

    /** TC-01/TC-10 checkpoint negative: a changed worktree is never reviewed. */
    public function test_checkpoint_drift_is_persisted_before_any_provider_call(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-CHECKPOINT');
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/app/Example.php', "<?php\n\n// drift\n"));
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']);

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame(0, $adapter->turnCount);
        self::assertSame(2, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('invocation_outcome', ReviewInvocationOutcome::CHECKPOINT_ERROR->value)
            ->where('failure_code', 'checkpoint_worktree_dirty')->count());
    }

    /** AC-10: typed slot-materialization failures survive the review boundary. */
    public function test_missing_approval_reviewers_keep_their_typed_failure_code(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-REVIEWERS-MISSING');
        $snapshot = $prepared['run']->agent_profile_snapshot;
        self::assertIsArray($snapshot);
        $snapshot['reviewers'] = [];
        $prepared['run']->forceFill([
            'agent_profile_snapshot' => $snapshot,
            'version' => $prepared['run']->version + 1,
        ])->save();
        $adapter = $this->reviewAdapter([]);

        $job = $this->executeReview($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::FAILED, $job->state);
        self::assertSame('approval_reviewers_missing', $job->failure_code);
        self::assertSame(0, $adapter->turnCount);
    }

    /** TC-11: provider prose remains redacted evidence and has no mutation authority. */
    public function test_instruction_shaped_evidence_cannot_mutate_run_ticket_policy_or_slots(): void
    {
        $prepared = $this->preparedReviewRun('AI6-023-TC11');
        $before = $prepared['run']->fresh();
        $ticket = TicketReadModel::query()->where('project_id', $before->project_id)->sole();
        $ticketBytes = $ticket->redacted_content;
        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::UNTRUSTED_EVIDENCE,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ]);

        $job = $this->executeReview($before);

        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        self::assertSame(2, $adapter->turnCount);
        $after = $before->fresh();
        self::assertSame($before->scope_hash, $after->scope_hash);
        self::assertSame($before->ticket_contract_sha256, $after->ticket_contract_sha256);
        self::assertSame($before->security_policy_hash, $after->security_policy_hash);
        self::assertSame($before->agent_profile_snapshot, $after->agent_profile_snapshot);
        self::assertSame($ticketBytes, $ticket->fresh()->redacted_content);
        self::assertCount(2, $this->orchestratorReviewSlots($after));

        $result = ReviewResult::query()->where('run_id', $after->id)
            ->where('slot_id', $this->reviewSlotIds[0])->sole();
        $artifact = $result->rawArtifact()->firstOrFail();
        $bytes = file_get_contents(rtrim(config('ai6.run_artifacts.root'), '/\\').DIRECTORY_SEPARATOR.$artifact->storage_reference);
        self::assertIsString($bytes);
        self::assertStringNotContainsString('review-secret', $bytes);
        self::assertStringContainsString(RedactionMatchType::SECRET->marker(), $bytes);
    }
}
