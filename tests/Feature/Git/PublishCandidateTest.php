<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\CandidateProvenancePreflight;
use App\AI6\Git\CanonicalDiff;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\PublishCandidateException;
use App\AI6\Git\PublishCandidateService;
use App\AI6\Git\RunBranchName;
use App\AI6\Git\RunTreeService;
use App\AI6\Git\TicketReadModelPublisher;
use App\AI6\Git\TicketReadModelRefreshResult;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\HumanLoop\PublishHumanRequestBinding;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateCollector;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\PublishCompletionService;
use App\AI6\Runs\RecordedScopeRenderer;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class PublishCandidateTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;
    use BuildsRunWorkspaceGitFixture;

    #[After]
    public function removeCandidateFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    public function test_the_candidate_is_deterministic_idempotently_bound_and_creates_no_commit_or_ref(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-CANDIDATE', "second\n");
        $service = $this->app->make(PublishCandidateService::class);
        $refs = $this->runWorkspaceGit(['show-ref'], $prepared['repository']);
        $commits = trim($this->runWorkspaceGit(['rev-list', '--all', '--count'], $prepared['repository']));

        $first = $service->prospect($prepared['run']);
        $second = $service->prospect($prepared['run']);

        self::assertEquals($first, $second);
        self::assertSame($prepared['base'], $first->baseSha);
        self::assertSame($refs, $this->runWorkspaceGit(['show-ref'], $prepared['repository']));
        self::assertSame($commits, trim($this->runWorkspaceGit(['rev-list', '--all', '--count'], $prepared['repository'])));
        $candidateDiff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'],
            $prepared['base'],
            $first->treeOid,
            new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-test'),
        );
        self::assertSame(['a.txt'], array_column($candidateDiff->entries, 'path'));

        $bound = $service->bind($prepared['run'], $first);
        self::assertSame($first->treeOid, $bound->candidate_tree_sha);
        self::assertSame($first->diffHash, $bound->candidate_diff_hash);
        self::assertSame($prepared['checkpoint'], $bound->candidate_checkpoint_commit_sha);
        $approval = TicketApproval::query()->findOrFail($bound->ticket_approval_id);
        self::assertSame($approval->ticket_contract_sha256, $bound->candidate_ticket_contract_sha256);
        self::assertSame($approval->approval_snapshot_hash, $bound->candidate_approval_snapshot_hash);
        $version = $bound->version;
        self::assertSame($version, $service->bind($bound, $first)->version, 'A repeated identical binding is a no-op.');
    }

    public function test_the_final_commit_and_publish_intent_keep_the_exact_candidate_parent_and_remote_binding(): void
    {
        $prepared = $this->preparedCandidate('AI6-029-PUBLISH', "published\n");
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
        $run = $this->app->make(PublishCandidateService::class)->bind($prepared['run'], $candidate);
        $run = $this->app->make(RunOrchestrator::class)->transition($run, $run->version, RunState::RUNNING, RunPhase::PUBLISH);
        self::assertInstanceOf(Run::class, $run);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->prepareBranchPublication($run, $run->version, str_repeat('0', 64));
        $attempt = $this->app->make(ManagedProjectPath::class)->prepareAttempt(
            (string) $run->project()->value('project_identifier'),
            $run->id,
            2,
        );
        $context = new RedactionContext((string) $run->project_id, $run->id, 'publish-intent-test');
        $commit = $this->app->make(HardenedGitRunner::class)->createSingleParentCommit(
            $prepared['repository'],
            $candidate->treeOid,
            $prepared['base'],
            'AI6-029-PUBLISH: publish accepted candidate',
            'AI6',
            'ai6@localhost',
            (int) $run->final_commit_timestamp,
            $attempt.DIRECTORY_SEPARATOR.'publish-message',
            $context,
        );
        $shape = $this->app->make(HardenedGitRunner::class)->inspectSingleParentCommit(
            $prepared['repository'],
            $commit,
            $context,
        );

        self::assertSame($candidate->treeOid, $shape['tree_oid']);
        self::assertSame($prepared['base'], $shape['parent_oid']);
        $run = $orchestrator->bindPublishIntent($run, $run->version, $commit, str_repeat('0', 64), true);
        $boundVersion = $run->version;
        self::assertSame($boundVersion, $orchestrator->bindPublishIntent(
            $run,
            $run->version,
            $commit,
            str_repeat('0', 64),
            true,
        )->version);
        $run = $orchestrator->confirmBranchPublication($run, $run->version, $commit);
        self::assertSame($commit, $run->confirmed_branch_publication_oid);
        self::assertSame('confirmed', $run->branch_publication_state);
    }

    public function test_no_change_publication_records_no_fictitious_commit_parent_tuple(): void
    {
        $prepared = $this->preparedCandidate('AI6-029-NO-CHANGE', '', noChange: true);
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
        $run = $this->app->make(PublishCandidateService::class)->bind($prepared['run'], $candidate);
        $run = $this->app->make(RunOrchestrator::class)->transition($run, $run->version, RunState::RUNNING, RunPhase::PUBLISH);
        self::assertInstanceOf(Run::class, $run);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->prepareBranchPublication($run, $run->version, str_repeat('0', 64));

        $bound = $orchestrator->bindPublishIntent(
            $run,
            $run->version,
            $run->run_base_sha,
            str_repeat('0', 64),
            false,
        );

        self::assertSame('no_change', $bound->final_commit_kind);
        self::assertSame($run->run_base_sha, $bound->branch_publication_target_oid);
        self::assertNull($bound->final_commit_oid);
        self::assertNull($bound->final_commit_tree_oid);
        self::assertNull($bound->final_commit_parent_oid);

        $statusCommit = str_repeat('f', 64);
        self::assertSame(1, Run::query()->whereKey($bound->id)->update([
            'run_base_sha' => $statusCommit,
            'candidate_invalidated_at' => now(),
            'version' => DB::raw('version + 1'),
        ]));
        self::assertSame($run->run_base_sha, $bound->fresh()->branch_publication_target_oid);
        self::assertSame($statusCommit, $bound->fresh()->run_base_sha);
    }

    /** TC-17 */
    public function test_publish_rechecks_the_candidate_binding_before_any_git_effect(): void
    {
        Mail::fake();
        $prepared = $this->preparedCandidate('AI6-029-STALE-PUBLISH', "stale candidate\n");
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
        $run = $this->app->make(PublishCandidateService::class)->bind($prepared['run'], $candidate);
        Run::query()->whereKey($run->id)->update([
            'phase' => RunPhase::PUBLISH->value,
            'state' => 'running',
            'candidate_invalidated_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        $run = Run::query()->findOrFail($run->id);
        $refs = $this->runWorkspaceGit(['show-ref'], $prepared['repository']);
        $job = ExecutionJob::query()->create([
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::PUBLISH->value,
            'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::PUBLISH, 1),
            'state' => ExecutionJobState::PLANNED,
            'attempts' => 0,
        ]);
        $owner = 'worker:publish-stale';
        $claimed = $this->app->make(RunOrchestrator::class)->claimStep($job, $owner);
        self::assertInstanceOf(ExecutionJob::class, $claimed);

        $this->app->make(PublishCompletionService::class)->execute($claimed, $run, $owner);

        self::assertSame(ExecutionJobState::WAITING, $job->fresh()->state);
        self::assertSame(WaitReason::GIT_BASE_CHANGED, $run->fresh()->wait_reason);
        self::assertNull($run->fresh()->final_commit_oid);
        self::assertNull($run->fresh()->confirmed_branch_publication_oid);
        self::assertSame($refs, $this->runWorkspaceGit(['show-ref'], $prepared['repository']));
        self::assertSame(1, HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', 'open')->count());
    }

    public function test_publish_status_conflict_releases_operation_and_scope_binding_for_reauthorization(): void
    {
        Mail::fake();
        $prepared = $this->preparedCandidate('AI6-029-STATUS-CONFLICT', "status conflict\n");
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
        $run = $this->app->make(PublishCandidateService::class)->bind($prepared['run'], $candidate);
        $run = $this->app->make(RunOrchestrator::class)->transition($run, $run->version, RunState::RUNNING, RunPhase::PUBLISH);
        self::assertInstanceOf(Run::class, $run);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->prepareBranchPublication($run, $run->version, str_repeat('0', 64));
        $run = $orchestrator->bindPublishIntent($run, $run->version, str_repeat('d', 64), str_repeat('0', 64), true);
        $run = $orchestrator->confirmBranchPublication($run, $run->version, str_repeat('d', 64));
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $project = Project::query()->findOrFail($run->project_id);
        $inProgress = $this->validTicketMarkdown($approval->ticket_id, 'in_progress');
        $administrator = User::query()->where('is_global_admin', true)->firstOrFail();
        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            $approval->relative_path,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $refreshAttempt = $this->app->make(ProjectOperationLease::class)->claim($refresh, str_repeat('f', 32));
        self::assertIsInt($refreshAttempt);
        $refreshParameters = json_decode($refresh->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($refreshParameters);
        $inProgressBlob = hash('sha256', 'blob '.strlen($inProgress)."\0".$inProgress);
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $inProgress,
            $approval->relative_path,
            TicketValidationProfile::GENERIC_V1,
        );
        $this->app->make(TicketReadModelPublisher::class)->publish(
            $project->refresh(),
            $refresh->refresh(),
            new TicketReadModelRefreshResult(
                $refresh->id,
                $project->id,
                $approval->relative_path,
                (string) $project->control_oid,
                $inProgressBlob,
                $inProgress,
            ),
            $projection,
            new RedactionContext((string) $project->id, $run->id, 'status-conflict-refresh'),
            now(),
            $refreshParameters['effective_config_hash'],
        );
        $this->finishOperation($refresh->refresh(), $refreshAttempt);
        $readModel = TicketReadModel::query()
            ->where('project_id', $project->id)
            ->where('relative_path', $approval->relative_path)
            ->sole();
        $statusTarget = str_replace(
            'status: in_progress',
            'status: review',
            $this->app->make(RecordedScopeRenderer::class)->write($run->fresh(), $inProgress),
        );
        $operation = $this->app->make(QueueTicketMutation::class)->completeImplementationRun(
            User::query()->findOrFail($approval->approved_by),
            $project->refresh(),
            $readModel,
            $run->fresh(),
            (string) Str::uuid(),
            $statusTarget,
            'Testbindung für den Publish-Statuskonflikt.',
        );
        DB::table('jobs')->delete();

        Run::query()->whereKey($run->id)->update([
            'state' => 'waiting',
            'wait_reason' => WaitReason::MANUAL_PUSH->value,
            'version' => DB::raw('version + 1'),
        ]);
        try {
            $orchestrator->bindPublishCompletionOperation(
                $run->fresh(),
                $run->fresh()->version,
                $operation,
                str_repeat('e', 64),
            );
            self::fail('An unrelated wait must not bind publish status synchronization.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('publish_status_sync_state_invalid', $conflict->reason);
        }
        Run::query()->whereKey($run->id)->update([
            'state' => 'running',
            'wait_reason' => null,
            'version' => DB::raw('version + 1'),
        ]);
        $run = $run->fresh();
        self::assertInstanceOf(Run::class, $run);
        $run = $orchestrator->bindPublishCompletionOperation(
            $run,
            $run->version,
            $operation,
            str_repeat('e', 64),
        );
        self::assertSame($operation->id, $run->pending_status_operation_id);
        self::assertSame(str_repeat('e', 64), $run->recorded_scope_sha256);
        $operationAttempt = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('c', 32));
        self::assertIsInt($operationAttempt);
        $this->finishOperation(
            $operation->refresh(),
            $operationAttempt,
            ControlOperationState::FAILED,
            'control_ref_changed',
        );

        $released = $this->app->make(PublishCompletionService::class)->recordConflict($operation->refresh());

        self::assertInstanceOf(Run::class, $released);
        self::assertSame(WaitReason::GIT_CONFLICT, $released->wait_reason);
        self::assertNull($released->pending_status_operation_id);
        self::assertNull($released->recorded_scope_sha256);
        $request = HumanRequest::query()->where('run_id', $released->id)->sole();
        self::assertTrue(PublishHumanRequestBinding::matchesStatusConflict($request, 'refresh_expected_oid'));
        self::assertSame(PublishHumanRequestBinding::AGENT_SLOT, $request->bound_agent_slot);
        self::assertStringContainsString('Branch ist bereits veröffentlicht', $request->message);
        self::assertStringNotContainsString('report-only', $request->message);

        $approver = $request->attentionUser()->firstOrFail();
        $this->actingAs($approver)
            ->get(route('projects.human-requests.show', [$project, $request->id]))
            ->assertOk()
            ->assertSee('aria-label="Step-up"', false)
            ->assertSee('value="refresh_expected_oid"', false);

        $this->actingAs($approver);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $proof,
            $approver,
            HumanRequestAnswerController::STEP_UP_ACTION,
        );
        $session->save();
        $answer = [
            'run_version' => $request->bound_run_version,
            'ticket_contract' => $request->bound_ticket_contract,
            'checkpoint' => $request->bound_checkpoint,
            'scope' => $request->bound_scope,
            'agent_slot' => $request->bound_agent_slot,
            'requested_effect' => $request->bound_requested_effect,
            'chosen_effect' => 'refresh_expected_oid',
        ];
        $blocker = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            $approval->relative_path,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [$project, $request->id]), $answer)
            ->assertRedirect(route('projects.human-requests.show', [$project, $request->id]))
            ->assertSessionHasErrors(['chosen_effect' => 'Eine andere Projektoperation ist aktiv. Bitte den Statusabgleich danach erneut versuchen.']);
        self::assertSame('open', $request->fresh()->resolution_state->value);
        $blockerAttempt = $this->app->make(ProjectOperationLease::class)->claim($blocker, str_repeat('a', 32));
        self::assertIsInt($blockerAttempt);
        $this->finishOperation($blocker->refresh(), $blockerAttempt);
        $retryProof = Request::create('/human-request', 'POST');
        $retryProof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $retryProof,
            $approver,
            HumanRequestAnswerController::STEP_UP_ACTION,
        );
        $session->save();

        $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [$project, $request->id]), $answer)
            ->assertRedirect(route('projects.runs.show', [$project, $run->id]));

        $syncing = $run->fresh();
        self::assertNotNull($syncing->pending_status_operation_id);
        self::assertNotSame($operation->id, $syncing->pending_status_operation_id);
        self::assertNotNull($syncing->recorded_scope_sha256);
        self::assertSame(WaitReason::STATUS_SYNC, $syncing->wait_reason);
        $intervention = Intervention::query()->where('human_request_id', $request->id)->sole();
        self::assertSame('refresh_expected_oid', $intervention->chosen_effect);
        self::assertSame($syncing->pending_status_operation_id, $intervention->status_operation_id);
        self::assertSame('answered', $request->fresh()->resolution_state->value);

        $ownOperation = ControlOperation::query()->findOrFail($syncing->pending_status_operation_id);
        $ownMutation = TicketMutation::query()->whereKey($ownOperation->id)->sole();
        $foreignOperationId = (string) Str::uuid();
        $foreignCommit = str_repeat('9', 64);
        $foreignOperationAttributes = $ownOperation->getAttributes();
        $foreignOperationAttributes['id'] = $foreignOperationId;
        $foreignOperationAttributes['request_hash'] = hash('sha256', $foreignOperationId);
        $foreignOperationAttributes['phase'] = ControlOperationPhase::PREPARED->value;
        $foreignOperationAttributes['state'] = ControlOperationState::QUEUED->value;
        $foreignOperationAttributes['attempts'] = 0;
        $foreignOperationAttributes['current_attempt_token'] = null;
        $foreignOperationAttributes['target_control_oid'] = null;
        $foreignOperationAttributes['started_at'] = null;
        $foreignOperationAttributes['completed_at'] = null;
        $foreignOperation = ControlOperation::query()->create($foreignOperationAttributes);
        $foreignMutationAttributes = $ownMutation->getAttributes();
        $foreignMutationAttributes['status_operation_id'] = $foreignOperationId;
        $foreignMutationAttributes['prepared_commit_oid'] = null;
        $foreignMutationAttributes['prepared_attempt_token'] = null;
        $foreignMutationAttributes['audit_redaction_matches'] = $ownMutation->audit_redaction_matches;
        $foreignMutation = TicketMutation::query()->create($foreignMutationAttributes);
        ControlOperation::query()->whereKey($foreignOperationId)->update([
            'state' => ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'started_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        TicketMutation::query()->whereKey($foreignOperationId)->update([
            'prepared_commit_oid' => $foreignCommit,
            'prepared_attempt_token' => 1,
        ]);
        ControlOperation::query()->whereKey($foreignOperationId)->update([
            'phase' => ControlOperationPhase::COMMIT_PREPARED,
            'target_control_oid' => $foreignCommit,
            'version' => DB::raw('version + 1'),
        ]);
        ControlOperationResult::query()->create([
            'control_operation_id' => $foreignOperationId,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('8', 64),
            'safe_summary' => 'Fremder persistierter Review-Statuscommit.',
        ]);
        ControlOperation::query()->whereKey($foreignOperationId)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        $foreignOperation = $foreignOperation->fresh();
        self::assertInstanceOf(ControlOperation::class, $foreignOperation);

        self::assertSame('review', $foreignMutation->fresh()->target_status);
        self::assertNull($this->app->make(PublishCompletionService::class)->reconcileOperation($foreignOperation));
        $unaffected = $run->fresh();
        self::assertInstanceOf(Run::class, $unaffected);
        self::assertSame(RunState::WAITING, $unaffected->state);
        self::assertSame(WaitReason::STATUS_SYNC, $unaffected->wait_reason);
        self::assertSame($ownOperation->id, $unaffected->pending_status_operation_id);
        self::assertSame($run->id, $project->fresh()->active_run_id);
        self::assertNotSame($foreignCommit, $ownMutation->fresh()->prepared_commit_oid);
    }

    public function test_the_candidate_preflight_rejects_a_secret_in_the_actual_blob(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-SECRET', "secret=verybadvalue\n");

        $this->expectException(PublishCandidateException::class);
        $this->expectExceptionMessage('candidate_secret_detected');
        $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
    }

    #[DataProvider('unsupportedCandidateBlobProvider')]
    public function test_the_candidate_preflight_names_unsupported_blob_content(string $content, string $reason): void
    {
        $prepared = $this->preparedCandidate('AI6-027-BLOB-'.strtoupper(substr($reason, -4)), $content);

        try {
            $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            self::fail('The unsupported candidate blob was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedCandidateBlobProvider(): iterable
    {
        yield 'larger than the provenance inspection limit' => [str_repeat('x', 1048577), 'candidate_blob_too_large'];
        yield 'non UTF-8 binary modification' => ["\xFF\xFE\x00binary", 'candidate_blob_not_utf8'];
    }

    public function test_the_candidate_preflight_rejects_a_symlink_entry(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-SYMLINK', "target\n", '120000');

        $this->expectException(PublishCandidateException::class);
        $this->expectExceptionMessage('candidate_symlink_forbidden');
        $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
    }

    #[DataProvider('forbiddenCandidateEntryProvider')]
    public function test_the_candidate_preflight_rejects_other_forbidden_entries(
        string $suffix,
        ?string $mode,
        bool $approveScope,
        string $reason,
    ): void {
        $prepared = $this->preparedCandidate('AI6-027-'.$suffix, "entry\n", $mode, $approveScope);

        try {
            if ($mode === '160000') {
                $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-gitlink');
                $checkpoint = $this->app->make(RunTreeService::class)->diff(
                    $prepared['run'], $prepared['base'], $prepared['checkpoint'], $context,
                );
                $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                    $prepared['run'], (string) $prepared['run']->checkpoint_tree_sha, $checkpoint, $checkpoint->entries,
                );
            } else {
                $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            }
            self::fail('The forbidden candidate entry was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{string, ?string, bool, string}> */
    public static function forbiddenCandidateEntryProvider(): iterable
    {
        yield 'gitlink' => ['GITLINK', '160000', true, 'candidate_gitlink_forbidden'];
        yield 'outside effective scope' => ['SCOPE', null, false, 'candidate_path_outside_effective_scope'];
    }

    public function test_the_candidate_preflight_rejects_an_unexpected_mode_and_a_change_outside_the_checkpoint(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-PREFLIGHT', "entry\n");
        $service = $this->app->make(PublishCandidateService::class);
        $candidate = $service->prospect($prepared['run']);
        $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-negative');
        $diff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'], $prepared['base'], $candidate->treeOid, $context,
        );
        $entry = $diff->entries[0];
        $entry['new_mode'] = '100600';
        $unexpectedMode = new CanonicalDiff([$entry], $diff->hash, $diff->redactedPresentation);

        try {
            $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                $prepared['run'], $candidate->treeOid, $unexpectedMode, $unexpectedMode->entries,
            );
            self::fail('The unexpected blob mode was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_blob_mode_forbidden', $exception->reason);
        }

        try {
            $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                $prepared['run'],
                $candidate->treeOid,
                $diff,
                [],
            );
            self::fail('A candidate change outside the checkpoint was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_change_not_from_checkpoint', $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    #[DataProvider('workspaceDriftProvider')]
    public function test_unknown_workspace_or_run_branch_drift_prevents_the_candidate(bool $commit): void
    {
        $prepared = $this->preparedCandidate('AI6-027-DRIFT-'.($commit ? 'COMMIT' : 'TREE'), "entry\n");
        self::assertNotFalse(file_put_contents($prepared['repository'].'/drift.txt', "unexpected\n"));
        if ($commit) {
            $this->runWorkspaceGit(['add', '--all'], $prepared['repository']);
            $this->runWorkspaceGit(['commit', '-m', 'unexpected commit'], $prepared['repository']);
        }

        try {
            $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            self::fail('The drifted workspace was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($commit ? 'run_branch_outside_checkpoint_protocol' : 'candidate_worktree_drift', $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{bool}> */
    public static function workspaceDriftProvider(): iterable
    {
        yield 'unknown worktree change' => [false];
        yield 'additional run branch commit' => [true];
    }

    public function test_the_candidate_is_reconstructed_on_the_new_run_base_without_rebasing_the_run_branch(): void
    {
        $ticketId = 'AI6-027-AMENDMENT';
        $prepared = $this->preparedCandidate($ticketId, "implementation\n");
        $repository = $prepared['repository'];
        $branchHead = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $this->runWorkspaceGit(['checkout', '--detach', $prepared['base']], $repository);
        $ticketPath = $repository.'/tickets/'.$ticketId.'.md';
        $amendedTicket = (string) file_get_contents($ticketPath)."\n## Recorded Scope\n\n- `a.txt` — automatisch aufgenommen.\n";
        self::assertNotFalse(file_put_contents($ticketPath, $amendedTicket));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'record amended ticket base'], $repository);
        $amendedBase = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $ticketBlob = trim($this->runWorkspaceGit(['rev-parse', $amendedBase.':tickets/'.$ticketId.'.md'], $repository));
        $runBranch = str_replace('refs/heads/', '', (string) $prepared['run']->run_branch);
        $this->runWorkspaceGit(['checkout', $runBranch], $repository);

        $prepared['run']->project()->update(['control_oid' => $amendedBase]);
        $ticketContract = $prepared['run']->ticket_contract_sha256
            ?? TicketApproval::query()->findOrFail($prepared['run']->ticket_approval_id)->ticket_contract_sha256;
        $run = $this->app->make(RunOrchestrator::class)->applyContractAmendment(
            $prepared['run'],
            $amendedBase,
            $ticketBlob,
            $ticketContract,
            (array) $prepared['run']->scope_snapshot,
            (string) $prepared['run']->scope_hash,
            (array) $prepared['run']->config_snapshot,
            (string) $prepared['run']->config_hash,
            (array) $prepared['run']->prompt_snapshot,
            (string) $prepared['run']->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );
        // The isolated AI6-owned ticket patch is recorded once on the existing
        // run-branch history. Candidate reconstruction may discard that delta
        // only because its resulting blob equals the latest run-base ticket.
        self::assertNotFalse(file_put_contents($ticketPath, $amendedTicket));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'recheck amended ticket base'], $repository);
        $rechecked = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $checkpointDiff = $this->app->make(RunTreeService::class)->diff(
            $run,
            (string) $run->initial_run_base_sha,
            $rechecked,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-amendment-checkpoint'),
        );
        self::assertContains('tickets/'.$ticketId.'.md', array_column($checkpointDiff->entries, 'path'));
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run,
            $run->version,
            $rechecked,
            trim($this->runWorkspaceGit(['rev-parse', $rechecked.'^{tree}'], $repository)),
            $checkpointDiff->hash,
        );
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($run);

        self::assertSame($amendedBase, $candidate->baseSha);
        self::assertTrue($this->runWorkspaceGit(['merge-base', '--is-ancestor', $branchHead, $rechecked], $repository) === '');
        self::assertSame($rechecked, trim($this->runWorkspaceGit(['rev-parse', $run->run_branch], $repository)));
        self::assertSame($amendedTicket, $this->runWorkspaceGit(['show', $candidate->treeOid.':tickets/'.$ticketId.'.md'], $repository));
        $bound = $this->app->make(PublishCandidateService::class)->bind($run, $candidate);
        self::assertSame($amendedBase, $bound->candidate_base_sha);
        $attempt = $this->app->make(ManagedProjectPath::class)->prepareAttempt(
            (string) $bound->project()->value('project_identifier'),
            $bound->id,
            2,
        );
        $context = new RedactionContext((string) $bound->project_id, $bound->id, 'candidate-amendment-final-commit');
        $commit = $this->app->make(HardenedGitRunner::class)->createSingleParentCommit(
            $repository,
            $candidate->treeOid,
            (string) $bound->run_base_sha,
            $ticketId.': publish amended candidate',
            'AI6',
            'ai6@localhost',
            (int) $bound->final_commit_timestamp,
            $attempt.DIRECTORY_SEPARATOR.'publish-message',
            $context,
        );
        $shape = $this->app->make(HardenedGitRunner::class)->inspectSingleParentCommit($repository, $commit, $context);
        self::assertSame($candidate->treeOid, $shape['tree_oid']);
        self::assertSame($amendedBase, $shape['parent_oid']);
        self::assertNotSame($prepared['base'], $shape['parent_oid']);
    }

    public function test_a_discarded_ticket_delta_must_end_at_the_exact_run_base_blob(): void
    {
        $ticketId = 'AI6-027-TICKET-NEUTRAL';
        $prepared = $this->preparedCandidate($ticketId, "implementation\n");
        $repository = $prepared['repository'];
        $ticketPath = $repository.'/tickets/'.$ticketId.'.md';
        $changed = str_replace('status: in_progress', 'status: review', (string) file_get_contents($ticketPath));
        self::assertNotFalse(file_put_contents($ticketPath, $changed));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'unexpected ticket delta'], $repository);
        $checkpoint = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-ticket-neutral');
        $diff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'], (string) $prepared['run']->initial_run_base_sha, $checkpoint, $context,
        );
        self::assertContains('tickets/'.$ticketId.'.md', array_column($diff->entries, 'path'));
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $prepared['run'],
            $prepared['run']->version,
            $checkpoint,
            trim($this->runWorkspaceGit(['rev-parse', $checkpoint.'^{tree}'], $repository)),
            $diff->hash,
        );

        try {
            $this->app->make(PublishCandidateService::class)->prospect($run);
            self::fail('A non-neutral discarded ticket delta was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_ticket_not_neutral', $exception->reason);
        }
        self::assertNull($run->fresh()->candidate_tree_sha);
    }

    /** @return array{run: Run, repository: string, base: string, checkpoint: string} */
    private function preparedCandidate(
        string $ticketId,
        string $content,
        ?string $entryMode = null,
        bool $approveScope = true,
        bool $noChange = false,
    ): array {
        $root = $this->runWorkspaceRoot();
        $managed = $root.'/managed';
        $identifier = str_repeat('a', 32);
        self::assertTrue(mkdir($managed, 0700));
        self::assertTrue(mkdir($managed.'/projects', 0700));
        self::assertTrue(mkdir($managed.'/projects/'.$identifier, 0700));
        config([
            'ai6.control_operations.managed_root' => $managed,
            'ai6.control_operations.key_root' => $managed.'/deploy-keys',
            'ai6.control_operations.known_hosts_file' => $managed.'/known_hosts',
        ]);
        foreach ([ControlOperationConfiguration::class, ManagedProjectPath::class, InstructionCandidateCollector::class, ApprovalSnapshotFactory::class] as $service) {
            $this->app->forgetInstance($service);
        }
        [$repository] = $this->runWorkspaceRepository($managed.'/projects/'.$identifier);
        self::assertTrue(mkdir($repository.'/tickets', 0700));
        self::assertNotFalse(file_put_contents(
            $repository.'/tickets/'.$ticketId.'.md',
            $this->validTicketMarkdown($ticketId, 'in_progress'),
        ));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'bind in-progress ticket'], $repository);
        $base = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));

        $runner = $this->runWorkspaceRunner($root);
        $this->app->instance(HardenedGitRunner::class, $runner);
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        $attention = $this->createUser(['email' => 'attention-'.strtolower($ticketId).'@example.test']);
        $fixture = $this->completedApproval($ticketId, attentionUser: $attention);
        $run = $this->finalizedRun($fixture, $base);
        $branch = RunBranchName::forRun((string) $fixture['project']->project_identifier, $run->id);
        $this->runWorkspaceGit(['branch', '-m', $branch->shortName()], $repository);
        $run = $this->app->make(RunOrchestrator::class)->bindWorkspace($run, $run->version, $branch->value, $repository);

        if (! $noChange) {
            self::assertNotFalse(file_put_contents($repository.'/a.txt', $content));
            $this->runWorkspaceGit(['add', '--all'], $repository);
        }
        if ($entryMode !== null && ! $noChange) {
            $object = $entryMode === '160000'
                ? $base
                : trim($this->runWorkspaceGit(['hash-object', '-w', 'a.txt'], $repository));
            $this->runWorkspaceGit(['update-index', '--add', '--cacheinfo', $entryMode.','.$object.',a.txt'], $repository);
        }
        $this->runWorkspaceGit(['commit', '--allow-empty', '-m', 'checkpoint'], $repository);
        $checkpoint = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        if ($entryMode === '160000') {
            self::assertTrue(unlink($repository.'/a.txt'));
        }

        foreach ([RunTreeService::class, CandidateProvenancePreflight::class, PublishCandidateService::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $tree = trim($runner->resolveTree(
            $repository,
            $checkpoint,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-fixture'),
        )->output);
        $diff = $this->app->make(RunTreeService::class)->diff(
            $run,
            $base,
            $checkpoint,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-fixture'),
        );
        if ($approveScope && ! $noChange) {
            $run = $this->app->make(RunOrchestrator::class)->applyScopeDecision(
                $run,
                'a.txt',
                true,
                null,
                1,
                $this->app->make(CanonicalJson::class),
                'auto_allow',
            );
        }
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run,
            $run->version,
            $checkpoint,
            $tree,
            $diff->hash,
        );

        return compact('run', 'repository', 'base', 'checkpoint');
    }
}
