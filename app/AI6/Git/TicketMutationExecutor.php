<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\EffectLockHandle;
use App\AI6\Shared\Process\EffectLockOutcome;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketStatusOperation;
use App\AI6\Tickets\TicketStatusTransitionPolicy;
use App\AI6\Tickets\TicketV1Parser;
use App\AI6\Tickets\TicketValidationConfiguration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class TicketMutationExecutor
{
    public function __construct(
        private ManagedProjectPath $paths,
        private ProjectOperationLease $lease,
        private ProjectEffectLockName $lockNames,
        private EffectLock $effectLock,
        private HardenedGitRunner $git,
        private ControlRemoteProbe $remote,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private TicketReadModelFreshness $freshness,
        private TicketReadModelUsePolicy $usePolicy,
        private TicketReadModelProjector $projector,
        private TicketReadModelPublisher $publisher,
        private TicketValidationConfiguration $validation,
        private TicketV1Parser $parser,
        private TicketStatusTransitionPolicy $transitions,
        private Redactor $redactor,
        private RefreshPathPolicy $refreshPaths,
        private CanonicalJson $canonicalJson,
        private ControlOperationConfiguration $controlConfiguration,
        private TicketMutationConfiguration $configuration,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $operation->refresh();
        $this->assertType($operation);

        try {
            return match ($operation->phase) {
                ControlOperationPhase::PREPARED => $this->prepareCommit($operation, $attemptToken),
                ControlOperationPhase::COMMIT_PREPARED => $this->publish($operation, $attemptToken),
                ControlOperationPhase::CONTROL_CONFIRMED => $this->finalize($operation, $attemptToken),
                ControlOperationPhase::DB_FINALIZED => true,
                default => throw new RuntimeException('The ticket mutation has an incompatible phase.'),
            };
        } catch (ControlOperationTerminalConflict $exception) {
            if ($this->publishedIntent($operation)) {
                throw new ControlOperationRecoveryRequired(
                    'A published ticket mutation encountered a terminal preflight deviation.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /** @return array<string, scalar|null>|null */
    public function activeIntentUnderLock(ControlOperation $operation, int $attemptToken): ?array
    {
        $this->assertType($operation);
        $mutation = $operation->ticketMutation()->firstOrFail();

        return $mutation->prepared_commit_oid === null ? null : [
            'commit_oid' => $mutation->prepared_commit_oid,
            'expected_parent' => $operation->expected_control_commit,
            'relative_path' => $mutation->relative_path,
        ];
    }

    public function cleanupFailedAttempt(ControlOperation $operation, int $attemptToken): void
    {
        $this->assertType($operation);
        $operation->refresh();
        if ($operation->state === ControlOperationState::RECOVERY_REQUIRED
            || $operation->phase === ControlOperationPhase::RECOVERY_REQUIRED) {
            return;
        }
        $project = $operation->project()->firstOrFail();
        if ($project->project_identifier !== null) {
            $repositoryPath = $this->paths->repositoryDirectory($project->project_identifier);
            if (is_dir($repositoryPath)) {
                $repository = $this->paths->assertRepository($repositoryPath);
                $mutation = $operation->ticketMutation()->firstOrFail();
                $anchorAttemptToken = $mutation->prepared_attempt_token ?? $attemptToken;
                $attemptRef = ManagedProjectPath::attemptRef($operation->id, $anchorAttemptToken);
                $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-cleanup');
                $resolved = $this->git->resolveAttemptRef($repository, $attemptRef, $context);
                if ($resolved->succeeded()) {
                    $oid = trim($resolved->output);
                    if ($mutation->prepared_commit_oid === null || ! hash_equals($mutation->prepared_commit_oid, $oid)) {
                        throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref differs from its persisted commit.');
                    }
                    $deleted = $this->git->deleteAttemptRef($repository, $attemptRef, $oid, $context);
                    if (! $deleted->succeeded()) {
                        throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref could not be removed safely.');
                    }
                } elseif ($resolved->exitCode !== 1 || $resolved->output !== '' || $resolved->errorOutput !== '') {
                    throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref could not be inspected safely.');
                }
            }
            $mutation = $operation->ticketMutation()->firstOrFail();
            $anchorAttemptToken = $mutation->prepared_attempt_token ?? $attemptToken;
            $this->paths->removeOwnedAttempt($project->project_identifier, $operation->id, $anchorAttemptToken);
            if ($anchorAttemptToken !== $attemptToken) {
                $this->paths->removeOwnedAttempt($project->project_identifier, $operation->id, $attemptToken);
            }
        }
    }

    /** @return array{text: string, hash: string, effect_hash: string} */
    public function recoveryFinding(ControlOperation $operation, int $attemptToken, string $deviation): array
    {
        $this->assertType($operation);
        $project = $operation->project()->firstOrFail();
        $mutation = $operation->ticketMutation()->firstOrFail();
        $repository = $this->paths->assertRepository(
            $this->paths->repositoryDirectory((string) $project->project_identifier),
        );
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-recovery-finding');
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'ticket mutation recovery inspection');
        try {
            $effect = $this->canonicalJson->normalizeAndEncode(
                $this->recoveryEffectSnapshot($operation, $project, $mutation, $repository, $context),
            );
        } finally {
            $lock->release();
        }
        $effectHash = hash('sha256', "AI6-TICKET-MUTATION-EFFECT-V1\0".$effect);
        $finding = $this->canonicalJson->normalizeAndEncode([
            'control_operation_id' => $operation->id,
            'deviation' => $deviation,
            'effect_hash' => $effectHash,
            'project_id' => $operation->project_id,
        ]);

        return [
            'text' => 'Der Ticketmutations-Außenstand muss gegen den gebundenen Commit abgeglichen werden.',
            'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$finding),
            'effect_hash' => $effectHash,
        ];
    }

    public function retryRecovery(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->assertType($operation);
        $project = $operation->project()->firstOrFail();
        $mutation = $operation->ticketMutation()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $repository = $this->paths->assertRepository(
            $this->paths->repositoryDirectory((string) $project->project_identifier),
        );
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-recovery-retry');
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'ticket mutation recovery retry');
        try {
            $this->assertRecoveryEffectUnchanged($operation, $project, $mutation, $repository, $context);
            $commitOid = $this->preparedCommit($operation, $mutation, $repository, $context);
            $this->assertRecoverablePublishedEffect($operation, $project, $mutation, $repository, $commitOid, $context);

            DB::transaction(function () use ($operation, $decision, $attemptToken): void {
                $updated = ControlOperation::query()
                    ->whereKey($operation->id)
                    ->where('current_attempt_token', $attemptToken)
                    ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                    ->where('phase', ControlOperationPhase::RECOVERY_REQUIRED)
                    ->where('version', $operation->version)
                    ->where('finding_hash', $operation->finding_hash)
                    ->update([
                        'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
                        'state' => ControlOperationState::RUNNING,
                        'finding_text' => null,
                        'finding_hash' => null,
                        'recovery_attempt_token' => null,
                        'recovery_version' => null,
                        'recovery_effect_hash' => null,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => Date::now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The ticket mutation recovery decision lost its compare-and-swap binding.');
                }
                $this->markDecisionApplied($decision);
            });
        } finally {
            $lock->release();
        }
    }

    public function adoptExternalState(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->retryRecovery($operation, $decision);
    }

    public function abandon(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->assertType($operation);
        $project = $operation->project()->firstOrFail();
        $mutation = $operation->ticketMutation()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $repository = $this->paths->assertRepository(
            $this->paths->repositoryDirectory((string) $project->project_identifier),
        );
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-recovery-abandon');
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'ticket mutation abandonment');
        try {
            $this->assertRecoveryEffectUnchanged($operation, $project, $mutation, $repository, $context);
            $parentOid = (string) $operation->expected_control_commit;
            if (! hash_equals((string) $project->control_oid, $parentOid)
                || $project->control_binding_version !== $mutation->expected_control_binding_version) {
                throw new ControlOperationRecoveryRequired('A partially finalized ticket mutation cannot be abandoned safely.');
            }

            $remoteHead = $this->remote->resolve($project, (string) $project->control_branch, $context);
            if (! hash_equals($remoteHead, $parentOid)) {
                if ($mutation->prepared_commit_oid !== null && hash_equals($remoteHead, $mutation->prepared_commit_oid)) {
                    throw new ControlOperationRecoveryRequired(
                        'A published ticket mutation commit cannot be abandoned without rewriting control history.',
                    );
                }

                throw new ControlOperationRecoveryRequired('A foreign control head cannot be abandoned as this ticket mutation.');
            }

            $localHead = $this->localControlOid($repository, (string) $project->control_branch, $context);
            if (! hash_equals($localHead, $parentOid)) {
                throw new ControlOperationRecoveryRequired('The local control ref changed before ticket mutation abandonment.');
            }

            if ($mutation->prepared_commit_oid === null) {
                if ($operation->target_control_oid !== null) {
                    throw new ControlOperationRecoveryRequired('The unprepared ticket mutation has an unexpected target commit binding.');
                }
                foreach ($this->attemptRefs($operation, $repository, $context) as $attemptRef => $attemptRefOid) {
                    if (! $this->git->deleteAttemptRef(
                        $repository,
                        $attemptRef,
                        $attemptRefOid,
                        $context,
                    )->succeeded()) {
                        throw new ControlOperationRecoveryRequired('The unpersisted ticket mutation attempt ref could not be removed safely.');
                    }
                    $this->paths->removeOwnedAttempt(
                        (string) $project->project_identifier,
                        $operation->id,
                        $this->attemptTokenFromRef($operation, $attemptRef),
                    );
                }
            } else {
                $this->preparedCommit($operation, $mutation, $repository, $context);
            }

            DB::transaction(function () use ($operation, $decision, $attemptToken): void {
                $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'abandoned');
                $result = ControlOperationResult::query()->firstOrCreate(
                    ['control_operation_id' => $operation->id],
                    [
                        'outcome' => ControlOperationOutcome::ABANDONED,
                        'result_binding' => $binding,
                        'safe_summary' => 'Die unveröffentlichte Ticketmutation wurde mit gebundener Evidenz abgebrochen.',
                    ],
                );
                if ($result->outcome !== ControlOperationOutcome::ABANDONED
                    || ! hash_equals($result->result_binding, $binding)) {
                    throw new RuntimeException('The existing ticket mutation abandonment result has another binding.');
                }
                $updated = ControlOperation::query()
                    ->whereKey($operation->id)
                    ->where('current_attempt_token', $attemptToken)
                    ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                    ->where('phase', ControlOperationPhase::RECOVERY_REQUIRED)
                    ->where('version', $operation->version)
                    ->where('finding_hash', $operation->finding_hash)
                    ->update([
                        'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                        'state' => ControlOperationState::ABANDONED,
                        'recovery_attempt_token' => null,
                        'recovery_version' => null,
                        'recovery_effect_hash' => null,
                        'completed_at' => Date::now(),
                        'version' => DB::raw('version + 1'),
                        'updated_at' => Date::now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The ticket mutation abandonment lost its compare-and-swap binding.');
                }
                $this->markDecisionApplied($decision);
            });
        } finally {
            $lock->release();
        }

        $this->cleanupFailedAttempt($operation->refresh(), $attemptToken);
        $this->lease->releaseTerminal($operation->refresh(), $attemptToken);
    }

    private function prepareCommit(ControlOperation $operation, int $attemptToken): bool
    {
        [$project, $mutation, $repository, $context] = $this->preflight($operation, $attemptToken);
        $blob = $this->git->readRegularBlob(
            $repository,
            (string) $operation->expected_control_commit,
            $mutation->relative_path,
            $context,
        );
        $readModel = TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->where('relative_path', $mutation->relative_path)
            ->firstOrFail();
        if (! hash_equals($mutation->expected_ticket_blob_sha, $blob->blobSha)
            || ! hash_equals($mutation->base_content_sha256, hash('sha256', $blob->content))
            || ! hash_equals($mutation->base_content, $blob->content)
            || ! hash_equals($readModel->blob_sha, $blob->blobSha)
            || ! hash_equals($readModel->redacted_content, $blob->content)
            || $readModel->redaction_state !== TicketReadModelRedactionState::CLEAR
            || ! $this->usePolicy->allowsEditor($readModel, ! $this->freshness->for($project, $readModel)['stale'])) {
            throw new ControlOperationTerminalConflict(
                'ticket_blob_changed',
                'Die bytegenaue, unmaskierte Ticketbasis ist nicht mehr aktuell.',
            );
        }
        $targetRedaction = $this->redactor->redact($mutation->target_content, $context);
        if ($targetRedaction->matches !== [] || ! hash_equals($targetRedaction->text, $mutation->target_content)) {
            throw new ControlOperationTerminalConflict(
                'target_requires_redaction',
                'Der Zielstand enthält schützenswerte Inhalte oder Redactionmarker.',
            );
        }
        $projection = $this->projector->project(
            $mutation->target_content,
            $mutation->relative_path,
            $this->validation->profile,
        );
        if ($projection->state !== TicketDocumentState::VALID
            || $projection->contractHash === null
            || ! hash_equals($projection->contractHash, $mutation->target_contract_sha256)) {
            throw new ControlOperationTerminalConflict(
                'target_validation_changed',
                'Der vorbereitete Ticketstand ist nicht mehr gültig gebunden.',
            );
        }
        $this->assertStatusContract(
            $operation,
            $project,
            $readModel,
            $mutation,
            $projection->document?->frontmatter['status'] ?? null,
            $blob->blobSha,
        );

        $plan = $this->git->planSingleFileMutation(
            $repository,
            (string) $operation->expected_control_commit,
            $mutation->relative_path,
            $mutation->target_content,
            $context,
        );
        if (! hash_equals($mutation->expected_target_blob_sha, $plan['blob_oid'])) {
            throw new ControlOperationTerminalConflict('target_blob_mismatch', 'Der vorbereitete Zielblob stimmt nicht überein.');
        }
        $updated = TicketMutation::query()
            ->whereKey($mutation->status_operation_id)
            ->where('expected_target_tree_oid', str_repeat('0', 64))
            ->update(['expected_target_tree_oid' => $plan['tree_oid'], 'updated_at' => Date::now()]);
        if ($updated !== 1) {
            $mutation->refresh();
            if (! hash_equals($mutation->expected_target_tree_oid, $plan['tree_oid'])) {
                throw new ControlOperationTerminalConflict('target_tree_mismatch', 'Der vorbereitete Zielbaum stimmt nicht überein.');
            }
        }
        $attempt = $this->paths->prepareAttempt((string) $project->project_identifier, $operation->id, $attemptToken);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'commit preparation');
        try {
            $this->git->writeSingleFileMutation(
                $repository,
                (string) $operation->expected_control_commit,
                $mutation->relative_path,
                $mutation->target_content,
                $plan['blob_oid'],
                $plan['tree_oid'],
                $plan['mode'],
                $attempt.DIRECTORY_SEPARATOR.'mutation.index',
                $context,
            );
            $commitOid = $this->git->createSingleParentCommit(
                $repository,
                $plan['tree_oid'],
                (string) $operation->expected_control_commit,
                $this->commitMessage($operation, $mutation),
                $this->configuration->authorName,
                $this->configuration->authorEmail,
                $mutation->commit_timestamp,
                $attempt.DIRECTORY_SEPARATOR.'commit.message',
                $context,
            );
            $attemptRef = ManagedProjectPath::attemptRef($operation->id, $attemptToken);
            $attemptResult = $this->git->createAttemptRef($repository, $attemptRef, $commitOid, $context);
            if (! $attemptResult->succeeded()) {
                $existingAttempt = $this->git->resolveAttemptRef($repository, $attemptRef, $context);
                if (! $existingAttempt->succeeded() || ! hash_equals(trim($existingAttempt->output), $commitOid)) {
                    throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref could not be bound safely.');
                }
            }
        } finally {
            $lock->release();
        }

        $mutationUpdated = TicketMutation::query()
            ->whereKey($mutation->status_operation_id)
            ->whereNull('prepared_commit_oid')
            ->whereNull('prepared_attempt_token')
            ->update([
                'prepared_commit_oid' => $commitOid,
                'prepared_attempt_token' => $attemptToken,
                'updated_at' => Date::now(),
            ]);
        if ($mutationUpdated !== 1) {
            $mutation->refresh();
            if (! hash_equals((string) $mutation->prepared_commit_oid, $commitOid)
                || $mutation->prepared_attempt_token !== $attemptToken) {
                throw new ControlOperationRecoveryRequired('The prepared mutation commit changed before persistence.');
            }
        }
        $this->progress($operation, $attemptToken, ControlOperationPhase::PREPARED, [
            'phase' => ControlOperationPhase::COMMIT_PREPARED,
            'target_control_oid' => $commitOid,
        ]);

        return false;
    }

    private function publish(ControlOperation $operation, int $attemptToken): bool
    {
        [$project, $mutation, $repository, $context] = $this->preflight($operation, $attemptToken);
        $commitOid = $this->preparedCommit($operation, $mutation, $repository, $context);
        $remoteHead = $this->remote->resolve($project, (string) $project->control_branch, $context);
        if (! hash_equals($remoteHead, $commitOid)) {
            if (! hash_equals($remoteHead, (string) $operation->expected_control_commit)) {
                throw new ControlOperationTerminalConflict('control_head_changed', 'Der Remote-Control-Head ist nicht mehr der erwartete Parent.');
            }
            $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'ticket mutation publish');
            try {
                $result = $this->git->pushCommitCas(
                    $repository,
                    (string) $project->remote,
                    (string) $project->control_branch,
                    (string) $operation->expected_control_commit,
                    $commitOid,
                    (string) $project->deploy_key_reference,
                    $this->controlConfiguration->knownHostsFile,
                    (string) $project->host_key_fingerprint,
                    $context,
                );
            } finally {
                $lock->release();
            }
            if (! $result->succeeded()) {
                throw new ControlOperationTerminalConflict('push_cas_conflict', 'Der Control-Branch konnte nicht per Compare-and-Swap fortgeschrieben werden.');
            }
        }
        if (! hash_equals($this->remote->resolve($project, (string) $project->control_branch, $context), $commitOid)) {
            throw new ControlOperationRecoveryRequired('The remote did not confirm the prepared ticket mutation commit.');
        }
        $this->progress($operation, $attemptToken, ControlOperationPhase::COMMIT_PREPARED, [
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
        ]);

        return false;
    }

    private function finalize(ControlOperation $operation, int $attemptToken): bool
    {
        [$project, $mutation, $repository, $context] = $this->preflight($operation, $attemptToken, remoteMustBeParent: false);
        $commitOid = $this->preparedCommit($operation, $mutation, $repository, $context);
        if (! hash_equals($this->remote->resolve($project, (string) $project->control_branch, $context), $commitOid)) {
            throw new ControlOperationRecoveryRequired('The confirmed mutation commit is no longer the remote control head.');
        }
        $confirmedBlob = $this->git->readRegularBlob(
            $repository,
            $commitOid,
            $mutation->relative_path,
            $context,
        );
        if (! hash_equals($mutation->expected_target_blob_sha, $confirmedBlob->blobSha)) {
            throw new ControlOperationRecoveryRequired('The confirmed mutation commit contains a different ticket blob.');
        }
        $local = $this->git->resolveRef($repository, (string) $project->control_branch, $context);
        if (! $local->succeeded() || ! hash_equals(trim($local->output), $commitOid)) {
            $updatedRef = $this->git->updateRef(
                $repository,
                (string) $project->control_branch,
                $commitOid,
                (string) $operation->expected_control_commit,
                $context,
            );
            if (! $updatedRef->succeeded()) {
                throw new ControlOperationRecoveryRequired('The local control ref could not be finalized.');
            }
        }

        $projection = $this->projector->project($confirmedBlob->content, $mutation->relative_path, $this->validation->profile);
        if ($projection->state !== TicketDocumentState::VALID || $projection->contractHash === null) {
            throw new ControlOperationRecoveryRequired('The confirmed ticket content no longer produces its safe valid projection.');
        }
        $refreshResult = new TicketReadModelRefreshResult(
            $operation->id,
            (int) $project->getKey(),
            $mutation->relative_path,
            $commitOid,
            $confirmedBlob->blobSha,
            $confirmedBlob->content,
        );

        DB::transaction(function () use ($operation, $attemptToken, $project, $mutation, $commitOid, $projection, $refreshResult, $context): void {
            $now = Date::now();
            $current = ControlOperation::query()->findOrFail($operation->id);
            $currentProject = Project::query()->findOrFail($project->getKey());
            $projectFinalized = hash_equals((string) $currentProject->control_oid, $commitOid)
                && $currentProject->control_binding_version === $mutation->expected_control_binding_version + 1
                && $currentProject->operation_lock_operation_id === $operation->id
                && $currentProject->operation_lock_attempt_token === $attemptToken;
            if (! $projectFinalized) {
                $projectUpdated = Project::query()
                    ->whereKey($project->getKey())
                    ->where('operation_lock_operation_id', $operation->id)
                    ->where('operation_lock_attempt_token', $attemptToken)
                    ->where('control_oid', $operation->expected_control_commit)
                    ->where('control_binding_version', $mutation->expected_control_binding_version)
                    ->update([
                        'control_oid' => $commitOid,
                        'control_binding_version' => DB::raw('control_binding_version + 1'),
                    ]);
                $projectFinalized = $projectUpdated === 1;
            }
            if (! $projectFinalized
                || $current->state !== ControlOperationState::RUNNING
                || $current->phase !== ControlOperationPhase::CONTROL_CONFIRMED
                || $current->current_attempt_token !== $attemptToken) {
                throw new ControlOperationRetryableConflict('fencing_conflict', 'The mutation finalization lost its ownership binding.');
            }

            $currentProject = Project::query()->findOrFail($project->getKey());
            $published = $this->publisher->publish(
                $currentProject,
                $current,
                $refreshResult,
                $projection,
                $context,
                $now,
            );
            if ($published->document_state !== TicketDocumentState::VALID
                || $published->redaction_state !== TicketReadModelRedactionState::CLEAR
                || $published->ticket_contract_sha256 === null
                || ! hash_equals($mutation->target_contract_sha256, $published->ticket_contract_sha256)) {
                throw new ControlOperationRecoveryRequired('The confirmed ticket content no longer produces its safe valid projection.');
            }
            $binding = hash('sha256', "AI6-TICKET-MUTATION-RESULT-V1\0".$operation->id.$operation->request_hash.$commitOid.$refreshResult->blobSha);
            $result = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $operation->id],
                [
                    'outcome' => ControlOperationOutcome::SUCCEEDED,
                    'result_binding' => $binding,
                    'safe_summary' => 'Die Ticketmutation wurde auf dem Control-Branch bestätigt.',
                ],
            );
            if ($result->outcome !== ControlOperationOutcome::SUCCEEDED || ! hash_equals($result->result_binding, $binding)) {
                throw new RuntimeException('The existing mutation result has a different provenance binding.');
            }
            $operationUpdated = ControlOperation::query()
                ->whereKey($operation->id)
                ->where('state', ControlOperationState::RUNNING)
                ->where('phase', ControlOperationPhase::CONTROL_CONFIRMED)
                ->where('current_attempt_token', $attemptToken)
                ->update([
                    'phase' => ControlOperationPhase::DB_FINALIZED,
                    'state' => ControlOperationState::COMPLETED,
                    'completed_at' => $now,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            if ($operationUpdated !== 1 || ! $this->lease->release($operation->id, $operation->project_id, $attemptToken)) {
                throw new RuntimeException('The ticket mutation lost its terminal compare-and-swap binding.');
            }
        });
        $this->cleanupFailedAttempt($operation, $attemptToken);

        return true;
    }

    /** @return array{Project, TicketMutation, string, RedactionContext} */
    private function preflight(ControlOperation $operation, int $attemptToken, bool $remoteMustBeParent = true): array
    {
        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        $mutation = $operation->ticketMutation()->firstOrFail();
        $parameters = $this->parameters($operation);
        $authorized = $operation->operation_type === ControlOperationType::TICKET_EDIT
            ? $this->projectPolicy->editTicket($actor, $project)
            : $this->projectPolicy->changeTicketStatus($actor, $project);
        if (! $authorized || ! $this->authorizationSnapshots->matchesCurrent($operation, $actor, $project)) {
            throw new ControlOperationTerminalConflict('authorization_changed', 'Die Autorisierung der Ticketmutation ist nicht mehr aktuell.');
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The ticket mutation lost its project lease.');
        }
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->project_identifier === null
            || $project->control_branch === null
            || $project->remote === null
            || $project->deploy_key_reference === null
            || $project->host_key_fingerprint === null
            || $project->pending_control_oid !== null
            || ($remoteMustBeParent && (! hash_equals((string) $project->control_oid, (string) $operation->expected_control_commit)
                || $project->control_binding_version !== $mutation->expected_control_binding_version))
            || ! hash_equals($parameters['relative_path'], $mutation->relative_path)
            || $parameters['expected_binding_version'] !== $mutation->expected_control_binding_version) {
            throw new ControlOperationTerminalConflict('control_binding_changed', 'Die Control-Bindung der Ticketmutation ist nicht mehr aktuell.');
        }

        return [
            $project,
            $mutation,
            $this->paths->assertRepository($this->paths->repositoryDirectory($project->project_identifier)),
            new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation:'.$mutation->relative_path),
        ];
    }

    /** @return array{relative_path: string, expected_binding_version: int, status_operation?: string} */
    private function parameters(ControlOperation $operation): array
    {
        try {
            $decoded = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Ticket mutation parameters are malformed.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Ticket mutation parameters are incomplete.');
        }
        $parameters = $operation->operation_type->parameters($decoded);
        if (! is_string($parameters['relative_path'])) {
            throw new ControlOperationTerminalConflict('mutation_parameters_not_canonical', 'Die Mutationsparameter sind nicht kanonisch gebunden.');
        }
        try {
            $canonicalPath = $this->refreshPaths->canonicalizeCandidate($parameters['relative_path']);
        } catch (ControlOperationConflict) {
            throw new ControlOperationTerminalConflict('mutation_path_outside_ticket_base', 'Der Mutationspfad liegt außerhalb der konfigurierten Ticketbasis.');
        }
        if (! is_int($parameters['expected_binding_version'])
            || ! hash_equals($canonicalPath, $parameters['relative_path'])
            || ! hash_equals($operation->operation_parameters_jcs, $this->canonicalJson->normalizeAndEncode($parameters))) {
            throw new ControlOperationTerminalConflict('mutation_parameters_not_canonical', 'Die Mutationsparameter sind nicht kanonisch gebunden.');
        }

        /** @var array{relative_path: string, expected_binding_version: int, status_operation?: string} $parameters */
        return $parameters;
    }

    private function preparedCommit(
        ControlOperation $operation,
        TicketMutation $mutation,
        string $repository,
        RedactionContext $context,
    ): string {
        if ($mutation->prepared_commit_oid === null
            || $operation->target_control_oid === null
            || ! hash_equals($mutation->prepared_commit_oid, $operation->target_control_oid)) {
            throw new ControlOperationRecoveryRequired('The prepared mutation commit binding is incomplete.');
        }

        $shape = $this->git->inspectSingleParentCommit($repository, $mutation->prepared_commit_oid, $context);
        if (! hash_equals($mutation->expected_target_tree_oid, $shape['tree_oid'])) {
            throw new ControlOperationTerminalConflict('target_tree_mismatch', 'Der vorbereitete Zielbaum stimmt nicht überein.');
        }
        if (! hash_equals((string) $operation->expected_control_commit, $shape['parent_oid'])) {
            throw new ControlOperationTerminalConflict('prepared_parent_mismatch', 'Der vorbereitete Mutationscommit besitzt einen falschen Parent.');
        }

        return $mutation->prepared_commit_oid;
    }

    private function assertStatusContract(
        ControlOperation $operation,
        Project $project,
        TicketReadModel $readModel,
        TicketMutation $mutation,
        mixed $projectedStatus,
        string $actualBlobSha,
    ): void {
        if (! is_string($projectedStatus) || ! hash_equals($mutation->target_status, $projectedStatus)) {
            throw new ControlOperationTerminalConflict('target_status_mismatch', 'Der Zielstatus stimmt nicht mit der gebundenen Mutation überein.');
        }
        try {
            $sourceStatus = $this->parser->parse($mutation->base_content)->frontmatter['status'] ?? null;
        } catch (TicketParseException) {
            $sourceStatus = null;
        }
        if (is_string($sourceStatus) && ! in_array($sourceStatus, ['todo', 'ready', 'blocked', 'review'], true)) {
            throw new ControlOperationTerminalConflict('source_status_unavailable', 'Der Quellstatus ist nicht als Reparaturbasis gebunden.');
        }
        if (is_string($sourceStatus) && ! hash_equals($mutation->source_status, $sourceStatus)) {
            throw new ControlOperationTerminalConflict('source_status_mismatch', 'Der Quellstatus stimmt nicht mit der gebundenen Mutation überein.');
        }
        if (! is_string($sourceStatus) && $readModel->document_state !== TicketDocumentState::INVALID) {
            throw new ControlOperationTerminalConflict('source_status_unavailable', 'Der Quellstatus ist nicht als Reparaturbasis gebunden.');
        }

        $parameters = $this->parameters($operation);
        if ($operation->operation_type === ControlOperationType::TICKET_EDIT) {
            $expectedTarget = $mutation->source_status === 'ready' ? 'todo' : $mutation->source_status;
            if (! in_array($mutation->source_status, ['todo', 'ready', 'blocked'], true)
                || ! hash_equals($expectedTarget, $mutation->target_status)) {
                throw new ControlOperationTerminalConflict('edit_status_contract_changed', 'Die atomare Statuskopplung des Edits ist ungültig.');
            }

            return;
        }

        $statusOperation = isset($parameters['status_operation'])
            ? TicketStatusOperation::tryFrom($parameters['status_operation'])
            : null;
        $membership = ProjectMembership::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $operation->actor_id)
            ->first();
        if ($statusOperation === null || ! $membership instanceof ProjectMembership) {
            throw new ControlOperationTerminalConflict('status_operation_invalid', 'Die gebundene Statusoperation ist ungültig.');
        }
        try {
            $target = $this->transitions->decidePersisted(
                $membership->role,
                $statusOperation,
                $mutation->source_status,
                $mutation->expected_ticket_blob_sha,
                $actualBlobSha,
                (string) $operation->expected_control_commit,
                (string) $project->control_oid,
                $mutation->external_completion_confirmed,
            );
        } catch (TicketMutationConflict) {
            throw new ControlOperationTerminalConflict('status_transition_invalid', 'Der gebundene Statusübergang ist nicht mehr zulässig.');
        }
        if (! hash_equals($target, $mutation->target_status)) {
            throw new ControlOperationTerminalConflict('status_target_mismatch', 'Die Statusoperation führt nicht zum gebundenen Zielstatus.');
        }
    }

    private function commitMessage(ControlOperation $operation, TicketMutation $mutation): string
    {
        return implode("\n", [
            'AI6 ticket mutation',
            '',
            'AI6-Operation-ID: '.$operation->id,
            'AI6-Actor-ID: '.$operation->actor_id,
            'AI6-Reason: '.$mutation->audit_reason,
            'AI6-Previous-Blob: '.$mutation->expected_ticket_blob_sha,
            'AI6-Target-Blob: '.$mutation->expected_target_blob_sha,
            '',
        ]);
    }

    /** @return array<string, int|string|array<string, string>|null> */
    private function recoveryEffectSnapshot(
        ControlOperation $operation,
        Project $project,
        TicketMutation $mutation,
        string $repository,
        RedactionContext $context,
    ): array {
        if ($project->control_branch === null) {
            throw new ControlOperationRecoveryRequired('The ticket mutation recovery has no bound control ref.');
        }

        return [
            'prepared_commit_oid' => $mutation->prepared_commit_oid,
            'expected_parent_oid' => $operation->expected_control_commit,
            'expected_target_blob_sha' => $mutation->expected_target_blob_sha,
            'expected_target_tree_oid' => $mutation->expected_target_tree_oid,
            'attempt_refs' => $this->attemptRefs($operation, $repository, $context),
            'remote_control_oid' => $this->remote->resolve($project, $project->control_branch, $context),
            'local_control_oid' => $this->localControlOid($repository, $project->control_branch, $context),
            'project_control_oid' => $project->control_oid,
            'project_control_binding_version' => $project->control_binding_version,
            'expected_control_binding_version' => $mutation->expected_control_binding_version,
        ];
    }

    /** @return array<string, string> */
    private function attemptRefs(
        ControlOperation $operation,
        string $repository,
        RedactionContext $context,
    ): array {
        $prefix = 'refs/ai6/attempts/'.$operation->id.'/';
        $attemptRefs = [];
        foreach ($this->git->refs($repository, $context) as $ref => $oid) {
            if (! str_starts_with($ref, $prefix)) {
                continue;
            }
            $this->attemptTokenFromRef($operation, $ref);
            $attemptRefs[$ref] = $oid;
        }
        ksort($attemptRefs, SORT_STRING);

        return $attemptRefs;
    }

    private function attemptTokenFromRef(ControlOperation $operation, string $ref): int
    {
        $pattern = '/\Arefs\/ai6\/attempts\/'.preg_quote($operation->id, '/').'\/([1-9][0-9]*)\/control\z/D';
        if (preg_match($pattern, $ref, $matches) !== 1) {
            throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref has an invalid operation binding.');
        }
        $attemptToken = (int) $matches[1];
        if ($attemptToken < 1 || (string) $attemptToken !== $matches[1]) {
            throw new ControlOperationRecoveryRequired('The ticket mutation attempt ref has an invalid attempt binding.');
        }

        return $attemptToken;
    }

    private function assertRecoveryEffectUnchanged(
        ControlOperation $operation,
        Project $project,
        TicketMutation $mutation,
        string $repository,
        RedactionContext $context,
    ): void {
        $effect = $this->canonicalJson->normalizeAndEncode(
            $this->recoveryEffectSnapshot($operation, $project, $mutation, $repository, $context),
        );
        $effectHash = hash('sha256', "AI6-TICKET-MUTATION-EFFECT-V1\0".$effect);
        if ($operation->recovery_effect_hash === null
            || ! hash_equals($operation->recovery_effect_hash, $effectHash)) {
            throw new ControlOperationRecoveryRequired('The ticket mutation effect changed after the recovery finding.');
        }
    }

    private function assertRecoverablePublishedEffect(
        ControlOperation $operation,
        Project $project,
        TicketMutation $mutation,
        string $repository,
        string $commitOid,
        RedactionContext $context,
    ): void {
        $parentOid = (string) $operation->expected_control_commit;
        $remoteHead = $this->remote->resolve($project, (string) $project->control_branch, $context);
        if (! hash_equals($remoteHead, $commitOid)) {
            throw new ControlOperationRecoveryRequired('The remote control ref no longer contains the bound ticket mutation commit.');
        }
        $localHead = $this->localControlOid($repository, (string) $project->control_branch, $context);
        if (! hash_equals($localHead, $parentOid) && ! hash_equals($localHead, $commitOid)) {
            throw new ControlOperationRecoveryRequired('The local control ref is neither the bound parent nor the ticket mutation commit.');
        }
        $projectAtParent = hash_equals((string) $project->control_oid, $parentOid)
            && $project->control_binding_version === $mutation->expected_control_binding_version;
        $projectAtCommit = hash_equals((string) $project->control_oid, $commitOid)
            && $project->control_binding_version === $mutation->expected_control_binding_version + 1;
        if (! $projectAtParent && ! $projectAtCommit) {
            throw new ControlOperationRecoveryRequired('The project control binding cannot be reconciled with the ticket mutation intent.');
        }
    }

    private function localControlOid(string $repository, string $controlRef, RedactionContext $context): string
    {
        $local = $this->git->resolveRef($repository, $controlRef, $context);
        $oid = trim($local->output);
        if (! $local->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', $oid) !== 1) {
            throw new ControlOperationRecoveryRequired('The local ticket mutation control ref could not be resolved safely.');
        }

        return $oid;
    }

    private function recoveryAttemptToken(ControlOperation $operation): int
    {
        if ($operation->current_attempt_token === null) {
            throw new RuntimeException('The ticket mutation recovery decision has no bound operation attempt.');
        }

        return $operation->current_attempt_token;
    }

    private function markDecisionApplied(ControlOperationRecoveryDecision $decision): void
    {
        $updated = ControlOperationRecoveryDecision::query()
            ->whereKey($decision->id)
            ->where('state', 'pending')
            ->update([
                'state' => 'applied',
                'applied_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The ticket mutation recovery decision lost its single-consumption binding.');
        }
    }

    private function acquireEffectLock(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        string $section,
    ): EffectLockHandle {
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The ticket mutation lost its lease before '.$section.'.');
        }
        $lock = $this->effectLock->acquire($this->lockNames->forProject((string) $project->project_identifier));
        if (! $lock->acquired() || $lock->handle === null) {
            $conflict = match ($lock->outcome) {
                EffectLockOutcome::UNAVAILABLE => 'effect_lock_unavailable',
                EffectLockOutcome::CONFLICT => 'effect_lock_conflict',
                EffectLockOutcome::CONFIGURATION_ERROR => 'effect_lock_configuration',
                EffectLockOutcome::ACQUIRED => 'effect_lock_invalid_result',
            };
            throw new ControlOperationRetryableConflict($conflict, 'The effect lock for '.$section.' is unavailable: '.$lock->message);
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            $lock->handle->release();
            throw new ControlOperationRetryableConflict('lease_lost', 'The ticket mutation lost its lease under the effect lock.');
        }

        return $lock->handle;
    }

    private function publishedIntent(ControlOperation $operation): bool
    {
        $operation->refresh();
        if (in_array($operation->phase, [
            ControlOperationPhase::CONTROL_CONFIRMED,
            ControlOperationPhase::DB_FINALIZED,
            ControlOperationPhase::RECOVERY_REQUIRED,
        ], true)) {
            return true;
        }
        if ($operation->phase !== ControlOperationPhase::COMMIT_PREPARED) {
            return false;
        }

        $mutation = $operation->ticketMutation()->firstOrFail();
        if ($mutation->prepared_commit_oid === null) {
            return false;
        }
        $project = $operation->project()->firstOrFail();
        if ($project->control_branch === null) {
            return false;
        }
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'ticket-mutation-published-intent');

        try {
            $remoteHead = $this->remote->resolve($project, $project->control_branch, $context);
        } catch (\Throwable $exception) {
            throw new ControlOperationRecoveryRequired(
                'The published ticket mutation intent could not be inspected safely.',
                previous: $exception,
            );
        }

        return hash_equals($mutation->prepared_commit_oid, $remoteHead);
    }

    /** @param array<string, mixed> $updates */
    private function progress(ControlOperation $operation, int $attemptToken, ControlOperationPhase $phase, array $updates): void
    {
        $updates['version'] = DB::raw('version + 1');
        $updates['updated_at'] = Date::now();
        $updated = ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->where('state', ControlOperationState::RUNNING)
            ->where('phase', $phase)
            ->update($updates);
        if ($updated !== 1) {
            throw new ControlOperationRetryableConflict('fencing_conflict', 'The mutation progress lost its ownership binding.');
        }
    }

    private function assertType(ControlOperation $operation): void
    {
        if (! in_array($operation->operation_type, [ControlOperationType::TICKET_EDIT, ControlOperationType::TICKET_STATUS_CHANGE], true)) {
            throw new RuntimeException('The operation is not a ticket mutation.');
        }
    }
}
