<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Git\IsolatedTreeExport;
use App\AI6\Git\ReviewCheckpointException;
use App\AI6\Git\ReviewCheckpointVerifier;
use App\AI6\Git\WorktreeGitMetadataPaths;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Support\Str;
use Throwable;

/** One serial, checkpoint-bound quality-review round of a run. */
final readonly class ReviewRound
{
    public function __construct(
        private RunOrchestrator $orchestrator,
        private ReviewResultStore $results,
        private ReviewCheckpointVerifier $checkpoints,
        private IsolatedTreeExport $exporter,
        private CheckTreeBinding $trees,
        private ExecutionHomeManager $homes,
        private InstructionBindingVerifier $instructionBindings,
        private InstructionProfileRegistry $instructionProfiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private CredentialRevisionRegistry $credentialRevisions,
        private AgentAdapter $adapter,
        private AgentResultValidator $validator,
        private RunArtifactStore $artifacts,
        private RunLimitPolicy $limits,
        private HumanRequestService $humanRequests,
        private TicketV1Parser $tickets,
        private WorktreeGitMetadataPaths $gitMetadataPaths,
        private FixContextPackage $priorFindings,
        private EffectiveFindingState $findingStates,
        private PromptRenderer $prompts,
        private PromptCatalog $catalog,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        if ($run->phase->value !== 'review' || $run->review_readiness_state !== 'ready') {
            $this->failStep($job, $run, $owner, 'review_not_ready');

            return;
        }

        try {
            $slots = $this->orchestrator->materializeReviewSlots($run);
            $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
            $common = $this->approvalBindings($run, $approval);
            $criteria = $this->criterionRefs($run, $approval);
        } catch (Throwable $exception) {
            $reason = $exception instanceof ImplementationImportException
                ? $exception->reason
                : 'review_binding_missing';
            $this->failStep($job, $run, $owner, $reason);

            return;
        }

        $intent = [
            'effect' => 'execute_quality_review_round',
            'run_id' => $run->id,
            'round_number' => $job->step_number,
            'step_type' => ExecutionStepType::REVIEW->value,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::REVIEW, $job->step_number),
        ];
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                return;
            }
        } elseif (! $this->intentMatches($job->intent, $intent)) {
            $this->failStep($job, $run, $owner, 'invalid_step_intent');

            return;
        }

        foreach ($slots as $slot) {
            if ($this->results->terminalOutcome($run, $job->step_number, $slot->slot_id) !== null) {
                continue;
            }
            $maxAttempts = (int) config('ai6.run_steps.max_attempts', 3);
            while ($this->results->terminalOutcome($run, $job->step_number, $slot->slot_id) === null) {
                $attempt = $this->results->attempt($run, $job->step_number, $slot->slot_id);
                if ($job->step_number > 1 && $attempt === 1) {
                    $this->orchestrator->discardReviewSession($run, $slot->slot_id);
                }
                $slot = $this->orchestrator->bindReviewSession($run, $slot->slot_id, (string) Str::uuid());
                if ($this->invoke($job, $run, $slot, $common, $criteria, $attempt)) {
                    return;
                }
                $latest = $this->results->latestOutcome($run, $job->step_number, $slot->slot_id);
                if (! in_array($latest, [ReviewInvocationOutcome::PROVIDER_ERROR, ReviewInvocationOutcome::INVALID_JSON], true)) {
                    break;
                }
                if ($attempt >= $maxAttempts) {
                    $reason = $latest === ReviewInvocationOutcome::INVALID_JSON
                        ? WaitReason::INVALID_JSON
                        : WaitReason::PROVIDER_ERROR;
                    try {
                        $this->humanRequests->openFailureRequest($run, $job, $reason, $slot->slot_id);
                    } catch (HumanRequestRejected $exception) {
                        $this->failStep($job, $run, $owner, $exception->reason);
                    }

                    return;
                }
            }
        }

        foreach ($slots as $slot) {
            $terminal = $this->results->terminalOutcome($run, $job->step_number, $slot->slot_id);
            if ($terminal !== ReviewInvocationOutcome::VALID_RESULT) {
                $this->failStep($job, $run, $owner, 'review_slot_failed');

                return;
            }
        }

        if ($job->step_number > 1) {
            if (! $this->recordFixedPriorFindings($job, $run, $owner, $job->step_number, $slots)) {
                return;
            }
        }

        if ($this->orchestrator->applyPreparedStepEffect($run->fresh() ?? $run, ExecutionStepType::REVIEW, $job->step_number)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Qualitätsreview abgeschlossen.');
        }
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  list<string>  $criteria
     * @return bool true when the step was parked for a human answer
     */
    private function invoke(
        ExecutionJob $job,
        Run $run,
        RunAgent $slot,
        array $common,
        array $criteria,
        int $attempt,
    ): bool {
        $bindings = [
            ...$common,
            'slot_prompt_hash' => null,
            'slot_instruction_hash' => null,
            'slot_runtime_profile_hash' => null,
            'workspace_tree_hash' => null,
        ];
        try {
            $reviewer = $this->reviewerSnapshot($run, $slot);
            $prompt = $this->prompt($run, $slot, $job->step_number);
            $instruction = $this->instruction($run, $slot->provider_profile);
            $runtime = $this->runtime($run, $reviewer);
            $bindings['slot_prompt_hash'] = $prompt->hash;
            $bindings['slot_instruction_hash'] = $instruction->hash;
            $bindings['slot_runtime_profile_hash'] = $runtime->hash;
            $drift = $this->instructionBindings->driftCodeForProfile($run, $slot->provider_profile, $runtime->id);
            if ($drift !== null) {
                $this->orchestrator->discardReviewSession($run, $slot->slot_id);
                throw new ImplementationImportException($drift, 'The reviewer instruction or runtime binding changed.');
            }
        } catch (Throwable $exception) {
            $reason = match (true) {
                $exception instanceof ImplementationImportException => $exception->reason,
                $exception instanceof PromptRenderingException => $exception->reason->value,
                default => 'review_binding_invalid',
            };
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::BINDING_ERROR, $bindings, $reason);

            return false;
        }

        $context = new RedactionContext((string) $run->project_id, $run->id, 'quality-review-'.$slot->slot_id);
        try {
            $this->checkpoints->verify($run, $context);
        } catch (ReviewCheckpointException $exception) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::CHECKPOINT_ERROR, $bindings, $exception->reason);

            return false;
        }

        $roots = $this->executionRoots();
        if ($roots === null || ! is_string($run->worktree_path)) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_workspace_unavailable');

            return false;
        }
        [$inputRoot, $outputRoot] = $roots;
        $invocation = $this->invocationRoots($inputRoot, $outputRoot);
        if ($invocation === null) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_workspace_unavailable');

            return false;
        }
        [$invocationInput, $invocationOutput] = $invocation;
        $export = $invocationInput.DIRECTORY_SEPARATOR.'export';
        $home = null;
        $adapterIo = null;
        try {
            $this->exporter->export($run->worktree_path, $export);
            $bindings['workspace_tree_hash'] = $this->trees->hash($export);
            $expectedTree = $this->results->expectedWorkspaceHash($run, $job->step_number);
            if ($expectedTree !== null && ! hash_equals($expectedTree, $bindings['workspace_tree_hash'])) {
                throw new ImplementationImportException('review_workspace_binding_mismatch', 'Review workspaces do not bind the same tree.');
            }
            $home = $this->homes->create(
                $invocationInput,
                $invocationOutput,
                $slot->slot_id,
                $slot->session_id,
                $export,
                $this->instructionProfiles->get($slot->provider_profile),
                $instruction,
                $runtime,
                new CredentialProjection(
                    $slot->provider_profile,
                    $this->credentialRevisions->revision($slot->provider_profile),
                    [],
                ),
            );
            $adapterIo = $home->root.'-io';
        } catch (Throwable $exception) {
            $reason = $exception instanceof ImplementationImportException ? $exception->reason : 'review_workspace_unavailable';
            $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput);
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, $reason);

            return false;
        }

        $agentContext = new AgentResultContext(
            AgentRole::QUALITY_REVIEW,
            $prompt,
            $instruction,
            $runtime,
            $criteria,
            '',
            slotId: $slot->slot_id,
            attempt: $attempt,
            expectedFindingIds: $job->step_number > 1 ? $this->priorFindings->priorFindingIds($run, $job->step_number) : [],
        );
        $invocationLimit = $this->limits->consume(
            $run,
            ImportLimit::MAX_AGENT_INVOCATIONS,
            implode(':', [$job->idempotency_key, $slot->slot_id, $attempt]),
        );
        if ($invocationLimit instanceof ImportLimitResult) {
            $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput);
            try {
                $this->humanRequests->openLimitRequest($run, $job, $invocationLimit, WaitReason::RESOURCE_LIMIT);

                return true;
            } catch (HumanRequestRejected $exception) {
                $this->results->append(
                    $run,
                    $slot,
                    $job->step_number,
                    $attempt,
                    ReviewInvocationOutcome::HUMAN_REQUEST_ERROR,
                    $bindings,
                    $exception->reason,
                );

                return false;
            }
        }
        try {
            $bytes = $this->adapter->turn($agentContext, $home->workspace, [
                ...$this->gitMetadataPaths->resolve($run->worktree_path),
            ]);
        } catch (Throwable) {
            $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput);
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error');

            return false;
        }
        $afterTurn = Run::query()->find($run->id);
        if (! $afterTurn instanceof Run || $afterTurn->pending_status_operation_id !== null
            || ! in_array($afterTurn->state, [RunState::QUEUED, RunState::RUNNING], true)) {
            $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput);

            return true;
        }
        if (! $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput)) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_home_cleanup_failed');

            return false;
        }

        $publishLimit = $this->limits->evaluate($run, [], [['bytes' => strlen($bytes)]], strlen($bytes));
        if ($publishLimit instanceof ImportLimitResult) {
            $this->humanRequests->openLimitRequest($run, $job, $publishLimit, WaitReason::RESOURCE_LIMIT);

            return true;
        }

        try {
            $artifact = $this->artifacts->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, [
                'role' => AgentRole::QUALITY_REVIEW->value,
                'slot_id' => $slot->slot_id,
                'round_number' => $job->step_number,
                'attempt' => $attempt,
            ], $context);
        } catch (InvalidRedactionInputException) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, 'invalid_utf8');

            return false;
        }
        try {
            $result = $this->validator->validate($bytes, $agentContext, $context);
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, 'invalid_json', artifactId: $artifact->id);

            return false;
        } catch (AgentResultValidationException $exception) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, $exception->reason->value, artifactId: $artifact->id);

            return false;
        }

        if ($result->status === AgentResultStatus::FAILED) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error', artifactId: $artifact->id);

            return false;
        }
        if ($result->status === AgentResultStatus::NEEDS_HUMAN && $result->humanRequest !== null) {
            $this->results->append(
                $run,
                $slot,
                $job->step_number,
                $attempt,
                ReviewInvocationOutcome::NEEDS_HUMAN,
                $bindings,
                resultStatus: $result->status->value,
                artifactId: $artifact->id,
            );
            try {
                $this->humanRequests->open($run, $result->humanRequest, $slot->slot_id, $job->idempotency_key);

                return true;
            } catch (HumanRequestRejected $exception) {
                $this->results->append(
                    $run,
                    $slot,
                    $job->step_number,
                    $attempt + 1,
                    ReviewInvocationOutcome::HUMAN_REQUEST_ERROR,
                    $bindings,
                    $exception->reason,
                );

                return false;
            }
        }

        try {
            $this->results->appendValid(
                $run,
                $slot,
                $job->step_number,
                $attempt,
                $bindings,
                $result,
                $artifact->id,
                $context,
            );
        } catch (ReviewResultParseException $exception) {
            $this->results->append(
                $run,
                $slot,
                $job->step_number,
                $attempt,
                ReviewInvocationOutcome::INVALID_JSON,
                $bindings,
                $exception->reason,
                artifactId: $artifact->id,
            );
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function approvalBindings(Run $run, TicketApproval $approval): array
    {
        $pairs = [
            'config_hash' => 'config_hash',
            'scope_hash' => 'scope_hash',
            'prompt_hash' => 'prompt_hash',
            'instruction_hash' => 'instruction_hash',
            'runtime_profile_hash' => 'runtime_profile_hash',
            'agent_profile_hash' => 'agent_profile_hash',
            'security_policy_hash' => 'security_policy_hash',
        ];
        foreach ($pairs as $runField => $approvalField) {
            $left = $run->getAttribute($runField);
            $right = $approval->getAttribute($approvalField);
            if (! is_string($left) || ! is_string($right) || ! hash_equals($right, $left)) {
                throw new ImplementationImportException('approval_binding_mismatch', 'The run no longer matches its approval snapshot.');
            }
        }
        foreach (['checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash'] as $field) {
            if (! is_string($run->getAttribute($field))) {
                throw new ImplementationImportException('checkpoint_binding_missing', 'The run has no complete checkpoint binding.');
            }
        }

        return [
            'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'approval_config_hash' => $approval->config_hash,
            'approval_scope_hash' => $approval->scope_hash,
            'approval_prompt_hash' => $approval->prompt_hash,
            'approval_instruction_hash' => $approval->instruction_hash,
            'approval_runtime_profile_hash' => $approval->runtime_profile_hash,
            'approval_agent_profile_hash' => $approval->agent_profile_hash,
            'approval_security_policy_hash' => $approval->security_policy_hash,
            'approval_snapshot_hash' => $approval->approval_snapshot_hash,
        ];
    }

    /** @return array<string, mixed> */
    private function reviewerSnapshot(Run $run, RunAgent $slot): array
    {
        $approvalSlotId = $slot->approval_slot_id ?? $slot->slot_id;
        foreach (($run->agent_profile_snapshot ?? [])['reviewers'] ?? [] as $reviewer) {
            if (is_array($reviewer) && ($reviewer['id'] ?? null) === $approvalSlotId) {
                if ($slot->slot_revision > 1) {
                    foreach (($run->agent_profile_snapshot ?? [])['reviewers'] ?? [] as $approved) {
                        if (is_array($approved)
                            && ($approved['provider_profile'] ?? null) === $slot->provider_profile
                            && ($approved['model'] ?? null) === $slot->model
                            && ($approved['effort'] ?? null) === $slot->effort
                            && ($approved['prompt_profile_id'] ?? null) === $slot->prompt_profile) {
                            return [...$approved, 'id' => $slot->slot_id];
                        }
                    }
                    throw new ImplementationImportException('reviewer_revision_not_approved', 'The reviewer revision is absent from the run-bound approval snapshot.');
                }
                foreach ([
                    'provider_profile' => $slot->provider_profile,
                    'model' => $slot->model,
                    'effort' => $slot->effort,
                    'prompt_profile_id' => $slot->prompt_profile,
                ] as $field => $expected) {
                    if (($reviewer[$field] ?? null) !== $expected) {
                        throw new ImplementationImportException('approval_reviewer_mismatch', 'The reviewer slot binding changed.');
                    }
                }

                return $reviewer;
            }
        }

        throw new ImplementationImportException('approval_reviewer_missing', 'The reviewer slot is absent from the approval snapshot.');
    }

    private function prompt(Run $run, RunAgent $slot, int $round): PromptSnapshot
    {
        $snapshot = ($run->prompt_snapshot ?? [])['review_profile_snapshots'][$slot->prompt_profile] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['rendered_prompts'] ?? null)
            || ! is_string($snapshot['rendered_prompts']['quality_review'] ?? null)
            || ! is_string($snapshot['prompt_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('prompt_binding_missing', 'The reviewer prompt binding is missing.');
        }

        $bound = new PromptSnapshot(
            is_string($snapshot['catalog_version'] ?? null) ? $snapshot['catalog_version'] : '1',
            is_array($snapshot['selected_profiles'] ?? null) ? $snapshot['selected_profiles'] : [],
            $snapshot['rendered_prompts'],
            $snapshot['prompt_snapshot_hash'],
        );
        if ($round === 1) {
            return $bound;
        }
        $entry = $this->catalog->entry('quality_review');
        $profile = $this->catalog->reviewProfile($slot->prompt_profile);
        if ($bound->catalogVersion !== $this->catalog->version
            || ($bound->selectedProfiles['quality_review'] ?? null) !== $slot->prompt_profile
            || ($snapshot['entry_version'] ?? null) !== $entry->version
            || ! is_string($snapshot['template_sha256'] ?? null)
            || ! hash_equals($snapshot['template_sha256'], hash('sha256', $entry->template))
            || ($snapshot['review_profile_version'] ?? null) !== $profile->version) {
            throw new ImplementationImportException('review_prompt_binding_mismatch', 'The reviewer prompt binding changed.');
        }
        $package = $this->priorFindings->priorForRound($run, $round);
        if ($package['finding_ids'] === []) {
            throw new ImplementationImportException('review_prior_findings_missing', 'The re-review has no bound prior findings.');
        }

        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $context = 'Ticketvertrag '.$approval->ticket_contract_sha256.' ('.$approval->relative_path.")\n\n"
            ."Frühere gebundene Findings, je einmal einzustufen:\n".$package['json'];

        return $this->prompts->snapshot([
            new PromptRenderRequest('quality_review', new PromptVariables(['context' => $context]), $slot->prompt_profile),
        ], new RedactionContext((string) $run->project_id, $run->id, 'quality-review-prompt-'.$slot->slot_id));
    }

    /** @param iterable<RunAgent> $slots */
    private function recordFixedPriorFindings(ExecutionJob $job, Run $run, string $owner, int $round, iterable $slots): bool
    {
        $slotIds = [];
        foreach ($slots as $slot) {
            $slotIds[] = $slot->slot_id;
        }
        $expected = $slotIds;
        sort($expected, SORT_STRING);
        $nothingToFix = $this->results->nothingToFixResults($run, $round);
        $evidence = $nothingToFix->first();
        $nothingSlots = $nothingToFix->pluck('slot_id')->all();
        sort($nothingSlots, SORT_STRING);
        if ($nothingSlots !== $expected || ! $evidence instanceof ReviewResult) {
            return true;
        }
        foreach ($this->priorFindings->priorFindingsForRound($run, $round) as $finding) {
            if ($finding->checkpoint_tree_sha === $run->checkpoint_tree_sha) {
                continue;
            }
            if (! $this->findingStates->blocks($finding, $run)) {
                continue;
            }
            $fixed = $this->priorFindings->fixedSlotIds($run, $finding, $round);
            sort($fixed, SORT_STRING);
            if ($fixed !== $expected) {
                continue;
            }
            $fresh = $run->fresh();
            if ($fresh === null) {
                continue;
            }
            try {
                $this->orchestrator->recordFixedFinding(
                    $fresh,
                    $finding,
                    $evidence,
                    $fresh->version,
                    'Alle erforderlichen Reviewer haben den gebundenen Checkpoint als behoben eingestuft.',
                );
            } catch (RunTransitionConflict $conflict) {
                if ($conflict->reason === 'stale_run_version') {
                    $fresh = $run->fresh();
                    if ($fresh instanceof Run) {
                        try {
                            $this->orchestrator->recordFixedFinding(
                                $fresh,
                                $finding,
                                $evidence,
                                $fresh->version,
                                'Alle erforderlichen Reviewer haben den gebundenen Checkpoint als behoben eingestuft.',
                            );

                            continue;
                        } catch (RunTransitionConflict $retryConflict) {
                            if ($retryConflict->reason === 'stale_run_version') {
                                continue;
                            }
                            $this->failStep($job, $run, $owner, $retryConflict->reason);

                            return false;
                        }
                    }

                    continue;
                }
                $this->failStep($job, $run, $owner, $conflict->reason);

                return false;
            }
        }

        return true;
    }

    private function instruction(Run $run, string $provider): InstructionSnapshot
    {
        $snapshot = ($run->instruction_snapshot ?? [])[$provider] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['entries'] ?? null)
            || ! is_string($snapshot['instruction_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('instruction_binding_missing', 'The reviewer instruction binding is missing.');
        }
        $entries = [];
        foreach ($snapshot['entries'] as $entry) {
            if (! is_array($entry)) {
                throw new ImplementationImportException('instruction_binding_missing', 'The reviewer instruction binding is malformed.');
            }
            $value = new InstructionSnapshotEntry(
                (string) ($entry['discovery_name'] ?? ''),
                (string) ($entry['scope'] ?? ''),
                (int) ($entry['priority'] ?? 0),
                (string) ($entry['repository_path'] ?? ''),
                (string) ($entry['blob_sha'] ?? ''),
                (string) ($entry['effective_content'] ?? ''),
                is_array($entry['imports'] ?? null) ? array_values(array_filter($entry['imports'], 'is_string')) : [],
            );
            if (($entry['content_sha256'] ?? null) !== $value->contentSha256) {
                throw new ImplementationImportException('instruction_binding_mismatch', 'The reviewer instruction bytes changed.');
            }
            $entries[] = $value;
        }

        return new InstructionSnapshot($provider, $entries, $snapshot['instruction_snapshot_hash']);
    }

    /** @param array<string, mixed> $reviewer */
    private function runtime(Run $run, array $reviewer): ProviderRuntimeProfile
    {
        $runtimeId = $reviewer['runtime_profile_id'] ?? null;
        $bound = is_string($runtimeId) ? (($run->runtime_profile_snapshot ?? [])[$runtimeId] ?? null) : null;
        if (! is_string($runtimeId) || ! is_array($bound) || ! is_string($bound['hash'] ?? null)) {
            throw new ImplementationImportException('runtime_profile_binding_missing', 'The reviewer runtime binding is missing.');
        }
        $registered = $this->runtimeProfiles->get($runtimeId);
        if (! hash_equals($registered->hash, $bound['hash'])) {
            throw new ImplementationImportException('runtime_profile_drift', 'The reviewer runtime binding changed.');
        }

        return $registered;
    }

    /** @return list<string> */
    private function criterionRefs(Run $run, TicketApproval $approval): array
    {
        $readModel = TicketReadModel::query()->where('project_id', $run->project_id)
            ->where('relative_path', $approval->relative_path)->firstOrFail();
        try {
            return $this->tickets->parse($readModel->redacted_content)->acceptanceCriterionIds;
        } catch (TicketParseException) {
            throw new ImplementationImportException('ticket_contract_unavailable', 'The review criteria are unavailable.');
        }
    }

    /** @return array{string, string}|null */
    private function executionRoots(): ?array
    {
        $input = config('ai6.execution_mailboxes.agent_root');
        $output = config('ai6.execution_mailboxes.agent_output_root');
        if (! is_string($input) || ! is_string($output) || $input === '' || $output === '') {
            return null;
        }
        foreach ([$input, $output] as $root) {
            if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
                return null;
            }
        }
        $input = realpath($input);
        $output = realpath($output);
        if (! is_string($input) || ! is_string($output) || $input === $output || is_link($input) || is_link($output)) {
            return null;
        }

        return [$input, $output];
    }

    /** @return array{string, string}|null */
    private function invocationRoots(string $inputRoot, string $outputRoot): ?array
    {
        $name = 'review-invocation-'.bin2hex(random_bytes(12));
        $input = $inputRoot.DIRECTORY_SEPARATOR.$name;
        $output = $outputRoot.DIRECTORY_SEPARATOR.$name;
        if (! mkdir($input, 0700) || ! mkdir($output, 0700)) {
            $this->removeTree($input);
            $this->removeTree($output);

            return null;
        }
        $input = realpath($input);
        $output = realpath($output);
        if (! is_string($input) || ! is_string($output) || $input === $output || is_link($input) || is_link($output)) {
            if (is_string($input)) {
                $this->removeTree($input);
            }
            if (is_string($output)) {
                $this->removeTree($output);
            }

            return null;
        }

        return [$input, $output];
    }

    private function destroy(
        ?ExecutionHome $home,
        ?string $adapterIo,
        string $export,
        string $invocationInput,
        string $invocationOutput,
    ): bool {
        $complete = true;
        if ($home instanceof ExecutionHome) {
            try {
                $this->homes->destroy($home);
            } catch (Throwable) {
                $complete = false;
            }
        }
        if (is_string($adapterIo)) {
            $this->removeTree($adapterIo);
        }
        $this->removeTree($export);
        $this->removeTree($invocationInput);
        $this->removeTree($invocationOutput);

        return $complete
            && (! $home instanceof ExecutionHome || (! file_exists($home->root) && ! file_exists($home->outputRoot)))
            && (! is_string($adapterIo) || ! file_exists($adapterIo))
            && ! file_exists($export)
            && ! file_exists($invocationInput)
            && ! file_exists($invocationOutput);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        @chmod($path, 0700);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    /** @param array<string, scalar> $expected */
    private function intentMatches(string $stored, array $expected): bool
    {
        try {
            return json_decode($stored, true, 8, JSON_THROW_ON_ERROR) === $expected;
        } catch (\JsonException) {
            return false;
        }
    }

    private function failStep(ExecutionJob $job, Run $run, string $owner, string $code): void
    {
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Qualitätsreview fehlgeschlagen: '.$code.'.', $code);
        $this->orchestrator->failRun($run->id);
    }
}
