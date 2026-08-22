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
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Support\Str;
use Throwable;

/** The first serial, checkpoint-bound quality-review round of a run. */
final readonly class ReviewRound
{
    private const ROUND = 1;

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
        private HumanRequestService $humanRequests,
        private TicketV1Parser $tickets,
        private WorktreeGitMetadataPaths $gitMetadataPaths,
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
            'round_number' => self::ROUND,
            'step_type' => ExecutionStepType::REVIEW->value,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::REVIEW, 1),
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
            if ($this->results->terminalOutcome($run, self::ROUND, $slot->slot_id) !== null) {
                continue;
            }
            $attempt = $this->results->attempt($run, self::ROUND, $slot->slot_id);
            $slot = $this->orchestrator->bindReviewSession($run, $slot->slot_id, (string) Str::uuid());
            if ($this->invoke($job, $run, $slot, $common, $criteria, $attempt)) {
                return;
            }
        }

        foreach ($slots as $slot) {
            $terminal = $this->results->terminalOutcome($run, self::ROUND, $slot->slot_id);
            if ($terminal !== ReviewInvocationOutcome::VALID_RESULT) {
                $this->failStep($job, $run, $owner, 'review_slot_failed');

                return;
            }
        }

        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Qualitätsreview abgeschlossen.');
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
            $prompt = $this->prompt($run, $slot);
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
            $reason = $exception instanceof ImplementationImportException ? $exception->reason : 'review_binding_invalid';
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::BINDING_ERROR, $bindings, $reason);

            return false;
        }

        $context = new RedactionContext((string) $run->project_id, $run->id, 'quality-review-'.$slot->slot_id);
        try {
            $this->checkpoints->verify($run, $context);
        } catch (ReviewCheckpointException $exception) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::CHECKPOINT_ERROR, $bindings, $exception->reason);

            return false;
        }

        $roots = $this->executionRoots();
        if ($roots === null || ! is_string($run->worktree_path)) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_workspace_unavailable');

            return false;
        }
        [$inputRoot, $outputRoot] = $roots;
        $invocation = $this->invocationRoots($inputRoot, $outputRoot);
        if ($invocation === null) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_workspace_unavailable');

            return false;
        }
        [$invocationInput, $invocationOutput] = $invocation;
        $export = $invocationInput.DIRECTORY_SEPARATOR.'export';
        $home = null;
        $adapterIo = null;
        try {
            $this->exporter->export($run->worktree_path, $export);
            $bindings['workspace_tree_hash'] = $this->trees->hash($export);
            $expectedTree = $this->results->expectedWorkspaceHash($run, self::ROUND);
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
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, $reason);

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
        );
        try {
            $bytes = $this->adapter->turn($agentContext, $home->workspace, [
                ...$this->gitMetadataPaths->resolve($run->worktree_path),
            ]);
        } catch (Throwable) {
            $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput);
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error');

            return false;
        }
        if (! $this->destroy($home, $adapterIo, $export, $invocationInput, $invocationOutput)) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_home_cleanup_failed');

            return false;
        }

        try {
            $artifact = $this->artifacts->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, [
                'role' => AgentRole::QUALITY_REVIEW->value,
                'slot_id' => $slot->slot_id,
                'round_number' => self::ROUND,
                'attempt' => $attempt,
            ], $context);
        } catch (InvalidRedactionInputException) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, 'invalid_utf8');

            return false;
        }
        try {
            $result = $this->validator->validate($bytes, $agentContext, $context);
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, 'invalid_json', artifactId: $artifact->id);

            return false;
        } catch (AgentResultValidationException $exception) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, $exception->reason->value, artifactId: $artifact->id);

            return false;
        }

        if ($result->status === AgentResultStatus::FAILED) {
            $this->results->append($run, $slot, self::ROUND, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error', artifactId: $artifact->id);

            return false;
        }
        if ($result->status === AgentResultStatus::NEEDS_HUMAN && $result->humanRequest !== null) {
            $this->results->append(
                $run,
                $slot,
                self::ROUND,
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
                    self::ROUND,
                    $attempt + 1,
                    ReviewInvocationOutcome::HUMAN_REQUEST_ERROR,
                    $bindings,
                    $exception->reason,
                );

                return false;
            }
        }

        $this->results->append(
            $run,
            $slot,
            self::ROUND,
            $attempt,
            ReviewInvocationOutcome::VALID_RESULT,
            $bindings,
            resultStatus: $result->status->value,
            artifactId: $artifact->id,
        );

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
        foreach (($run->agent_profile_snapshot ?? [])['reviewers'] ?? [] as $reviewer) {
            if (is_array($reviewer) && ($reviewer['id'] ?? null) === $slot->slot_id) {
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

    private function prompt(Run $run, RunAgent $slot): PromptSnapshot
    {
        $snapshot = ($run->prompt_snapshot ?? [])['review_profile_snapshots'][$slot->prompt_profile] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['rendered_prompts'] ?? null)
            || ! is_string($snapshot['rendered_prompts']['quality_review'] ?? null)
            || ! is_string($snapshot['prompt_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('prompt_binding_missing', 'The reviewer prompt binding is missing.');
        }

        return new PromptSnapshot(
            is_string($snapshot['catalog_version'] ?? null) ? $snapshot['catalog_version'] : '1',
            is_array($snapshot['selected_profiles'] ?? null) ? $snapshot['selected_profiles'] : [],
            $snapshot['rendered_prompts'],
            $snapshot['prompt_snapshot_hash'],
        );
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
