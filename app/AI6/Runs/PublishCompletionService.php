<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\PublishCandidateException;
use App\AI6\Git\PublishCandidateService;
use App\AI6\Git\RunBranchName;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\HumanLoop\PublishHumanRequestBinding;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\SecurityReviewEvidence;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Tickets\TicketContentStatus;
use App\AI6\Tickets\TicketMutationConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Commit, branch publication and the bound post-push ticket-status saga. */
final readonly class PublishCompletionService
{
    private const ZERO_OID = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private PublishCandidateService $candidates,
        private CandidateGate $candidateGate,
        private RequiredReviewEvidence $reviews,
        private SecurityReviewEvidence $security,
        private SecurityPolicy $securityPolicy,
        private HardenedGitRunner $git,
        private ManagedProjectPath $paths,
        private ControlOperationConfiguration $configuration,
        private QueueTicketMutation $ticketMutations,
        private TicketContentStatus $statuses,
        private RecordedScopeRenderer $recordedScope,
        private RunOrchestrator $orchestrator,
        private HumanRequestService $humanRequests,
        private RunWorkspaceLifecycle $workspaces,
        private ProjectPolicy $policy,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        try {
            $run->refresh();
            $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
            $pushMode = self::resolvePushMode($approval->push_mode);
            $this->assertCurrentEvidence($run);
            if ($pushMode === 'manual' && ! $this->manualPushAuthorized($run)) {
                $this->humanRequests->openManualPushRequest($run, $job);

                return;
            }

            $project = Project::query()->findOrFail($run->project_id);
            $repository = $this->paths->assertRepository($this->paths->repositoryDirectory((string) $project->project_identifier));
            $context = new RedactionContext((string) $project->id, $run->id, 'publish-completion');
            $branch = new RunBranchName((string) $run->run_branch);
            $targetOid = $this->prepareCommit($run, $approval, $repository, $context);
            $run = $run->fresh() ?? $run;
            $run = $this->publishBranch($run, $project, $repository, $branch, $targetOid, $context);
            $this->startStatusSynchronization($run, $approval);
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Branch veröffentlicht und Statussynchronisation gebunden.');
        } catch (HumanRequestRejected) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die manuelle Pushentscheidung konnte nicht gebunden werden.', 'manual_push_request_failed');
            $this->orchestrator->failRun($run->id);
            $this->cleanupTerminalRun($run);
        } catch (Throwable $exception) {
            $reason = $exception instanceof RunTransitionConflict || $exception instanceof PublishCandidateException
                ? $exception->reason
                : 'publish_failed';
            if (in_array($reason, ['control_head_drift', 'candidate_binding_stale'], true)) {
                try {
                    $fresh = Run::query()->findOrFail($run->id);
                    $parked = $this->orchestrator->parkOnBaseDrift($fresh, $fresh->version);
                    $this->orchestrator->parkStep($job, $owner);
                    $this->humanRequests->openBaseDriftRequest($parked);

                    return;
                } catch (Throwable $parkingFailure) {
                    report($parkingFailure);
                }
            }
            $fresh = Run::query()->find($run->id);
            if ($fresh instanceof Run && $fresh->confirmed_branch_publication_oid !== null
                && $fresh->pending_status_operation_id === null
                && ! in_array($fresh->state, [RunState::COMPLETED, RunState::CANCELLED], true)) {
                try {
                    $parked = $this->orchestrator->parkOnGitConflict($fresh, $fresh->version);
                    $this->orchestrator->parkStep($job, $owner);
                    $this->humanRequests->openPublishStatusConflictRequest($parked);

                    return;
                } catch (Throwable $parkingFailure) {
                    report($parkingFailure);
                }
            }
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Publishabschluss wurde sicher blockiert.', $reason);
            $this->orchestrator->failRun($run->id);
            $this->cleanupTerminalRun($run);
        }
    }

    public static function resolvePushMode(string $mode): string
    {
        if (! in_array($mode, ['manual', 'automatic_after_gates'], true)) {
            throw new RunTransitionConflict('push_mode_invalid', 'Der gebundene Pushmodus ist ungültig.');
        }

        return $mode;
    }

    public function reconcileOperation(ControlOperation $operation): ?Run
    {
        $run = Run::query()->where('pending_status_operation_id', $operation->id)
            ->where('run_type', RunType::IMPLEMENTATION->value)->first();
        if (! $run instanceof Run || $run->state === RunState::COMPLETED) {
            return $run;
        }
        if ($operation->state !== ControlOperationState::COMPLETED) {
            return null;
        }
        $completed = $this->orchestrator->transition(
            $run,
            $run->version,
            RunState::COMPLETED,
            RunPhase::PUBLISH,
            confirmedStatusOperation: $operation,
        );
        $project = Project::query()->findOrFail($completed->project_id);
        $this->workspaces->reconcile(
            (string) $project->project_identifier,
            new RedactionContext((string) $project->id, $completed->id, 'publish-cleanup'),
        );
        $this->paths->removeOwnedOperation((string) $project->project_identifier, $completed->id);

        return $completed;
    }

    public function recordConflict(ControlOperation $operation): ?Run
    {
        $run = Run::query()->where('pending_status_operation_id', $operation->id)
            ->where('run_type', RunType::IMPLEMENTATION->value)->first();
        if (! $run instanceof Run) {
            return null;
        }
        $run = $this->orchestrator->parkOnGitConflict($run, $run->version);
        $run = $this->orchestrator->releaseCancellationOperation($run, $operation);
        if (! HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->exists()) {
            $this->humanRequests->openPublishStatusConflictRequest($run);
        }

        return $run;
    }

    public function resolveStatusConflict(HumanRequest $request, User $actor, ?InterventionAuthorization $authorization): Run
    {
        try {
            return DB::transaction(function () use ($request, $actor, $authorization): Run {
                DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
                $request = HumanRequest::query()->findOrFail($request->id);
                $run = Run::query()->findOrFail($request->run_id);
                if ($run->run_type !== RunType::IMPLEMENTATION || ! $authorization instanceof InterventionAuthorization) {
                    throw new HumanRequestRejected('step_up_required', 'Die Publish-Konfliktentscheidung verlangt eine frische starke Anmeldung.');
                }
                $project = Project::query()->findOrFail($run->project_id);
                $membership = ProjectMembership::query()->where('project_id', $project->id)
                    ->where('user_id', $actor->getKey())->first();
                if (! $this->policy->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $actor, $project)
                    || ! $membership instanceof ProjectMembership || $membership->role !== ProjectRole::APPROVER
                    || ! PublishHumanRequestBinding::matchesStatusConflict($request, 'refresh_expected_oid')
                    || $request->resolution_state !== HumanRequestResolutionState::OPEN
                    || $request->kind !== WaitReason::GIT_CONFLICT->value
                    || $run->state !== RunState::WAITING || $run->wait_reason !== WaitReason::GIT_CONFLICT
                    || $run->pending_status_operation_id !== null || $run->version !== $request->bound_run_version) {
                    throw new HumanRequestRejected('stale_status_conflict_decision', 'Die gebundene Publish-Konfliktentscheidung ist veraltet.');
                }
                $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
                $syncing = $this->startStatusSynchronization($run, $approval);
                Intervention::query()->create([
                    'id' => (string) Str::uuid(), 'human_request_id' => $request->id,
                    'user_id' => $actor->getKey(), 'actor_role' => $membership->role->value,
                    'step_up_verified' => true, 'step_up_proof_hash' => $authorization->proofHash,
                    'chosen_effect' => 'refresh_expected_oid', 'chosen_option_key' => 'refresh_expected_oid',
                    'expected_run_version' => $request->bound_run_version, 'wait_reason' => WaitReason::GIT_CONFLICT->value,
                    'bound_step_key' => $request->bound_step_key,
                    'reason' => 'Publish-Statusabgleich gegen die aktuelle Control-OID erneut autorisiert.',
                    'idempotency_key' => hash('sha256', $request->id.':publish-refresh-expected-oid'),
                    'status_operation_id' => $syncing->pending_status_operation_id,
                ]);
                $request->forceFill(['resolution_state' => HumanRequestResolutionState::ANSWERED, 'resolved_at' => now()])->save();

                return $syncing;
            });
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected($conflict->reason, $conflict->getMessage());
        } catch (TicketMutationConflict $conflict) {
            throw new HumanRequestRejected($conflict->conflict, $conflict->getMessage());
        } catch (Throwable $failure) {
            if ($failure instanceof AuthorizationException) {
                throw new HumanRequestRejected('publish_status_unauthorized', 'Die Publish-Statussynchronisation ist nicht mehr autorisiert.');
            }

            throw $failure;
        }
    }

    private function assertCurrentEvidence(Run $run): void
    {
        if ($run->phase !== RunPhase::PUBLISH || $run->candidate_invalidated_at !== null
            || $run->candidate_tree_sha === null || $run->candidate_diff_hash === null
            || $run->candidate_base_sha === null || ! hash_equals($run->candidate_base_sha, $run->run_base_sha)) {
            throw new RunTransitionConflict('candidate_binding_stale', 'Der Publish-Candidate ist nicht mehr aktuell gebunden.');
        }
        $actual = $this->candidates->prospect($run);
        if (! hash_equals($actual->treeOid, $run->candidate_tree_sha)
            || ! hash_equals($actual->diffHash, $run->candidate_diff_hash)
            || ! hash_equals($actual->baseSha, $run->candidate_base_sha)) {
            throw new RunTransitionConflict('candidate_binding_stale', 'Der tatsächliche Candidate weicht von der Bindung ab.');
        }
        $decision = $this->candidateGate->decide($run, $actual);
        $blockers = [...$this->reviews->blockers($run), ...$decision->blockers, ...$decision->openGates];
        if ($blockers !== []) {
            throw new RunTransitionConflict($blockers[0], 'Review- oder Gate-Evidenz ist nicht mehr aktuell.');
        }
        if ($this->securityPolicy->isEnabled(SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW)) {
            if (! $this->security->allowsContinuation($run)) {
                throw new RunTransitionConflict('security_result_stale', 'Das Security-Ergebnis ist nicht mehr aktuell.');
            }
        } elseif (! RunEvent::query()->where('run_id', $run->id)
            ->where('event_key', 'security-review-skipped:'.$run->candidate_tree_sha.':'.$run->security_policy_hash)->exists()) {
            throw new RunTransitionConflict('security_skip_missing', 'Der policybedingte Security-Skip ist nicht gebunden protokolliert.');
        }
    }

    private function prepareCommit(Run $run, TicketApproval $approval, string $repository, RedactionContext $context): string
    {
        $baseTree = $this->git->resolveTree($repository, $run->run_base_sha, $context);
        if (! $baseTree->succeeded()) {
            throw new RunTransitionConflict('run_base_unavailable', 'Die aktuelle Runbasis ist nicht auflösbar.');
        }
        $noChange = hash_equals(trim($baseTree->output), (string) $run->candidate_tree_sha);
        $expectedRemote = $run->branch_publication_expected_oid ?? $this->remoteOid($run, $repository, $context);
        if ($run->branch_publication_expected_oid === null) {
            $run = $this->orchestrator->prepareBranchPublication($run, $run->version, $expectedRemote);
        }
        if ($run->branch_publication_target_oid !== null) {
            return $run->branch_publication_target_oid;
        }
        if ($noChange) {
            $target = $run->run_base_sha;
        } else {
            $projectIdentifier = (string) Project::query()->whereKey($run->project_id)->value('project_identifier');
            $attempt = $this->paths->prepareAttempt($projectIdentifier, $run->id, 1);
            $target = $this->git->createSingleParentCommit(
                $repository,
                (string) $run->candidate_tree_sha,
                $run->run_base_sha,
                $approval->ticket_id.': publish accepted candidate',
                'AI6',
                'ai6@localhost',
                (int) $run->final_commit_timestamp,
                $attempt.DIRECTORY_SEPARATOR.'publish-message',
                $context,
            );
            $shape = $this->git->inspectSingleParentCommit($repository, $target, $context);
            if (! hash_equals((string) $run->candidate_tree_sha, $shape['tree_oid'])
                || ! hash_equals($run->run_base_sha, $shape['parent_oid'])) {
                throw new RunTransitionConflict('final_commit_binding_mismatch', 'Der finale Commit weicht von Candidate oder Runbasis ab.');
            }
        }
        $this->orchestrator->bindPublishIntent($run, $run->version, $target, $expectedRemote, ! $noChange);

        return $target;
    }

    private function publishBranch(Run $run, Project $project, string $repository, RunBranchName $branch, string $targetOid, RedactionContext $context): Run
    {
        $local = $this->git->resolveRunBranch($repository, $branch, $context);
        $localOid = $local->succeeded() ? trim($local->output) : self::ZERO_OID;
        if (! hash_equals($localOid, $targetOid)) {
            $updated = $this->git->updateRef($repository, $branch->value, $targetOid, $localOid === self::ZERO_OID ? null : $localOid, $context);
            if (! $updated->succeeded()) {
                throw new RunTransitionConflict('run_branch_drift', 'Der lokale Run-Branch konnte nicht gebunden fortgeschrieben werden.');
            }
        }
        $remoteOid = $this->remoteOid($run, $repository, $context);
        if (hash_equals($remoteOid, $targetOid)) {
            return $this->orchestrator->confirmBranchPublication($run, $run->version, $targetOid);
        }
        if (! hash_equals($remoteOid, (string) $run->branch_publication_expected_oid)) {
            throw new RunTransitionConflict('remote_branch_drift', 'Der Remote-Run-Branch ist gegenüber dem persistierten Intent gedriftet.');
        }
        $push = $this->git->pushCommitCas(
            $repository, (string) $project->remote, $branch->value, $remoteOid, $targetOid,
            (string) $project->deploy_key_reference, $this->configuration->knownHostsFile,
            (string) $project->host_key_fingerprint, $context,
        );
        if (! $push->succeeded() && ! hash_equals($this->remoteOid($run, $repository, $context), $targetOid)) {
            throw new RunTransitionConflict('branch_push_failed', 'Der Run-Branch konnte nicht unter der erwarteten Remote-OID veröffentlicht werden.');
        }

        return $this->orchestrator->confirmBranchPublication($run->fresh() ?? $run, ($run->fresh() ?? $run)->version, $targetOid);
    }

    private function remoteOid(Run $run, string $repository, RedactionContext $context): string
    {
        $project = Project::query()->findOrFail($run->project_id);
        $probe = $this->git->probeRemote(
            (string) $project->remote, (string) $run->run_branch, $repository,
            (string) $project->deploy_key_reference, $this->configuration->knownHostsFile,
            (string) $project->host_key_fingerprint, $context,
        );
        if ($probe->exitCode === 2 && trim($probe->output) === '') {
            return self::ZERO_OID;
        }
        if (! $probe->succeeded() || preg_match('/\A([0-9a-f]{64})\s/', trim($probe->output), $match) !== 1) {
            throw new RunTransitionConflict('remote_probe_failed', 'Der Remotezustand konnte nicht sicher bestimmt werden.');
        }

        return $match[1];
    }

    private function startStatusSynchronization(Run $run, TicketApproval $approval): Run
    {
        if ($run->pending_status_operation_id !== null) {
            return $run;
        }
        if ($run->recorded_scope_sha256 !== null) {
            throw new RunTransitionConflict(
                'publish_status_target_stale',
                'Die frühere Recorded-Scope-Bindung wurde vor der neuen Statusentscheidung nicht autorisiert freigegeben.',
            );
        }
        $project = Project::query()->findOrFail($run->project_id);
        $readModel = TicketReadModel::query()->where('project_id', $project->id)
            ->where('relative_path', $approval->relative_path)
            ->where('control_commit', $project->control_oid)->latest('generated_at')->first();
        if (! $readModel instanceof TicketReadModel) {
            throw new RunTransitionConflict('publish_status_binding_missing', 'Für den aktuellen Control-Stand fehlt die Ticketprojektion.');
        }
        $target = $this->statuses->replace(
            $this->recordedScope->write($run, $readModel->redacted_content),
            'in_progress',
            'review',
        );
        $actor = User::query()->findOrFail($approval->approved_by);
        $operation = $this->ticketMutations->completeImplementationRun(
            $actor, $project, $readModel, $run, (string) Str::uuid(), $target,
            'Gebundener Publishabschluss mit dokumentiertem effektivem Scope.',
        );

        return $this->orchestrator->bindPublishCompletionOperation(
            $run,
            $run->version,
            $operation,
            hash('sha256', $target),
        );
    }

    private function manualPushAuthorized(Run $run): bool
    {
        return Intervention::query()->where('chosen_effect', PublishHumanRequestBinding::AUTHORIZE_PUSH)
            ->whereHas('humanRequest', static fn ($query) => $query->where('run_id', $run->id))
            ->with('humanRequest')->get()
            ->contains(static fn (Intervention $intervention): bool => PublishHumanRequestBinding::matchesManualPush(
                $intervention->humanRequest,
                $run,
            ));
    }

    private function cleanupTerminalRun(Run $run): void
    {
        try {
            $project = Project::query()->findOrFail($run->project_id);
            $this->workspaces->reconcile(
                (string) $project->project_identifier,
                new RedactionContext((string) $project->id, $run->id, 'publish-failure-cleanup'),
            );
            $this->paths->removeOwnedOperation((string) $project->project_identifier, $run->id);
        } catch (Throwable $cleanupFailure) {
            report($cleanupFailure);
        }
    }
}
