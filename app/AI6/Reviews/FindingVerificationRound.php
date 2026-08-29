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
use App\AI6\Agents\FindingVerificationAssessment;
use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
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
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Reviews\Models\Finding;
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
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/** Executes advisory verification without changing finding effectiveness. */
final readonly class FindingVerificationRound
{
    public function __construct(
        private RunOrchestrator $orchestrator,
        private VerifierSlotSelector $selector,
        private ReviewResultStore $results,
        private AgentAdapter $adapter,
        private AgentResultValidator $validator,
        private ReviewCheckpointVerifier $checkpoints,
        private IsolatedTreeExport $exporter,
        private CheckTreeBinding $trees,
        private ExecutionHomeManager $homes,
        private InstructionProfileRegistry $instructionProfiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private InstructionBindingVerifier $instructionBindings,
        private CredentialRevisionRegistry $credentialRevisions,
        private WorktreeGitMetadataPaths $gitMetadataPaths,
        private RunArtifactStore $artifacts,
        private VerificationContextPackageStore $contextPackages,
        private RunLimitPolicy $limits,
        private HumanRequestService $humanRequests,
        private PromptCatalog $catalog,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        $intent = [
            'effect' => 'execute_finding_verification_round',
            'run_id' => $run->id,
            'round_number' => $job->step_number,
            'step_type' => ExecutionStepType::VERIFY->value,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::VERIFY, $job->step_number),
        ];
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                return;
            }
        } elseif (! $this->intentMatches($job->intent, $intent)) {
            $this->fail($job, $run, $owner, 'invalid_step_intent');

            return;
        }

        $findingIds = DB::table('findings')->where('run_id', $run->id)->where('round_number', $job->step_number)
            ->where('checkpoint_tree_sha', $run->checkpoint_tree_sha)->orderBy('duplicate_group')->orderBy('id')->pluck('id');
        if ($findingIds->isEmpty()) {
            $this->complete($job, $run, $owner);

            return;
        }
        $pool = ($run->agent_profile_snapshot ?? [])['verifier_candidates'] ?? null;
        if (! is_array($pool) || ! array_is_list($pool)) {
            $this->fail($job, $run, $owner, 'approval_verifier_pool_missing');

            return;
        }
        $implementationProfile = ($run->agent_profile_snapshot ?? [])['implementation']['profile_id'] ?? null;
        $forbiddenProfiles = is_string($implementationProfile) ? [$implementationProfile] : [];
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $bindings = $this->approvalBindings($run, $approval);

        $seenGroups = [];
        foreach ($findingIds as $findingId) {
            if (! is_string($findingId)) {
                continue;
            }
            $finding = Finding::query()->find($findingId);
            if (! $finding instanceof Finding) {
                continue;
            }
            if (isset($seenGroups[$finding->duplicate_group])) {
                continue;
            }
            $seenGroups[$finding->duplicate_group] = true;
            $candidate = $this->selector->select($pool, $this->groupProviderProfiles($run, $finding), $forbiddenProfiles);
            if (! $candidate instanceof VerifierCandidate) {
                $this->openDecision($run, $job, 'verification_independence', 'Kein unabhängiges Verifierprofil verfügbar', 'Das Quellprofil und der Implementierungsslot sind ausgeschlossen; der approval-gebundene Pool kann in diesem Run nicht erweitert werden.', 'controlled_abort');

                return;
            }
            $slot = $this->orchestrator->materializeVerifierSlot($run, $candidate, $finding, $job->step_number);
            $candidate = $this->candidateForSlot($pool, $slot);
            if (! $candidate instanceof VerifierCandidate) {
                $this->fail($job, $run, $owner, 'verifier_slot_not_approval_bound');

                return;
            }
            $maxAttempts = (int) config('ai6.run_steps.max_attempts', 3);
            while ($this->results->terminalOutcome($run, $job->step_number, $slot->slot_id) === null) {
                $attempt = $this->results->attempt($run, $job->step_number, $slot->slot_id);
                if ($this->invoke($job, $run, $slot, $candidate, $finding, $bindings, $attempt)) {
                    return;
                }
                $latest = $this->results->latestOutcome($run, $job->step_number, $slot->slot_id);
                if (! in_array($latest, [ReviewInvocationOutcome::PROVIDER_ERROR, ReviewInvocationOutcome::INVALID_JSON], true)) {
                    break;
                }
                if ($attempt >= $maxAttempts) {
                    $reason = $latest === ReviewInvocationOutcome::INVALID_JSON
                        ? WaitReason::INVALID_JSON : WaitReason::PROVIDER_ERROR;
                    try {
                        $this->humanRequests->openFailureRequest($run, $job, $reason, $slot->slot_id);
                    } catch (HumanRequestRejected $exception) {
                        $this->fail($job, $run, $owner, $exception->reason);
                    }

                    return;
                }
            }
            if ($this->results->terminalOutcome($run, $job->step_number, $slot->slot_id) !== ReviewInvocationOutcome::VALID_RESULT) {
                $this->fail($job, $run, $owner, 'verification_slot_failed');

                return;
            }
        }

        $this->complete($job, $run, $owner);
    }

    /** @param list<mixed> $pool */
    private function candidateForSlot(array $pool, RunAgent $slot): ?VerifierCandidate
    {
        foreach ($pool as $value) {
            if (is_array($value)
                && ($value['provider_profile'] ?? null) === $slot->provider_profile
                && ($value['model'] ?? null) === $slot->model
                && ($value['effort'] ?? null) === $slot->effort
                && is_string($value['id'] ?? null)
                && is_string($value['profile_id'] ?? null)) {
                return new VerifierCandidate(
                    $value['id'],
                    $value['profile_id'],
                    $slot->provider_profile,
                    $slot->model,
                    $slot->effort,
                    ['selected_from_approval_snapshot', 'human_authorized_slot_revision'],
                );
            }
        }

        return null;
    }

    /** @param array<string, mixed> $bindings */
    private function invoke(ExecutionJob $job, Run $run, RunAgent $slot, VerifierCandidate $candidate, Finding $finding, array $bindings, int $attempt): bool
    {
        $prompt = $this->prompt($run);
        $instruction = $this->instruction($run, $candidate->providerProfile);
        $runtime = $this->runtime($run, $candidate);
        $groupVerification = $this->groupHasMultipleFindings($run, $finding);
        $bindings += [
            'slot_prompt_hash' => $prompt->hash,
            'slot_instruction_hash' => $instruction->hash,
            'slot_runtime_profile_hash' => $runtime->hash,
            'workspace_tree_hash' => $run->checkpoint_tree_sha,
            'original_finding_id' => $groupVerification ? null : $finding->id,
            'original_duplicate_group' => $groupVerification ? $finding->duplicate_group : null,
        ];
        $context = new RedactionContext((string) $run->project_id, $run->id, 'finding-verification-'.$slot->slot_id);
        try {
            $this->checkpoints->verify($run, $context);
            $drift = $this->instructionBindings->driftCodeForProfile($run, $candidate->providerProfile, $runtime->id);
            if ($drift !== null) {
                throw new ImplementationImportException($drift, 'The verifier instruction or runtime binding changed.');
            }
        } catch (ReviewCheckpointException|ImplementationImportException $exception) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::BINDING_ERROR, $bindings, $exception->reason);

            return false;
        }
        $roots = $this->roots();
        if ($roots === null || ! is_string($run->worktree_path)) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'review_workspace_unavailable');

            return false;
        }
        [$input, $output] = $roots;
        $export = $input.DIRECTORY_SEPARATOR.'export';
        $home = null;
        $io = null;
        try {
            $this->exporter->export($run->worktree_path, $export);
            $bindings['workspace_tree_hash'] = $this->trees->hash($export);
            $expectedWorkspaceHash = $this->results->expectedWorkspaceHash($run, $job->step_number);
            if ($expectedWorkspaceHash !== null && ! hash_equals($expectedWorkspaceHash, $bindings['workspace_tree_hash'])) {
                throw new ImplementationImportException('review_workspace_hash_mismatch', 'The verifier workspace differs from the reviewed workspace.');
            }
            $home = $this->homes->create($input, $output, $slot->slot_id, (string) $slot->session_id, $export,
                $this->instructionProfiles->get($slot->provider_profile), $instruction, $runtime,
                new CredentialProjection($slot->provider_profile, $this->credentialRevisions->revision($slot->provider_profile), []));
            $io = $home->root.'-io';
            $this->contextPackages->store($run, $slot, $finding, $job->step_number, $bindings, $export, $context);
            $agentContext = new AgentResultContext(
                AgentRole::FINDING_VERIFICATION,
                $prompt,
                $instruction,
                $runtime,
                $finding->criterion_refs,
                '',
                slotId: $slot->slot_id,
                attempt: $attempt,
                expectedFindingIds: $groupVerification ? [] : [$finding->id],
                expectedFindingGroups: [$finding->duplicate_group],
            );
            $invocationLimit = $this->limits->consume(
                $run,
                ImportLimit::MAX_AGENT_INVOCATIONS,
                implode(':', [$job->idempotency_key, $slot->slot_id, $attempt]),
            );
            if ($invocationLimit instanceof ImportLimitResult) {
                $this->destroy($home, $io, $export, $input, $output);
                try {
                    $this->humanRequests->openLimitRequest($run, $job, $invocationLimit, WaitReason::RESOURCE_LIMIT);
                } catch (HumanRequestRejected $exception) {
                    $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::HUMAN_REQUEST_ERROR, $bindings, $exception->reason);
                }

                return true;
            }
            $bytes = $this->adapter->turn($agentContext, $home->workspace, $this->gitMetadataPaths->resolve($run->worktree_path));
        } catch (ReviewResultParseException $exception) {
            $this->destroy($home, $io, $export, $input, $output);
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::BINDING_ERROR, $bindings, $exception->reason);

            return false;
        } catch (Throwable) {
            $this->destroy($home, $io, $export, $input, $output);
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error');

            return false;
        }
        if (! $this->destroy($home, $io, $export, $input, $output)) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::WORKSPACE_ERROR, $bindings, 'verification_home_cleanup_failed');

            return false;
        }
        $afterTurn = Run::query()->find($run->id);
        if (! $afterTurn instanceof Run || $afterTurn->pending_status_operation_id !== null
            || ! in_array($afterTurn->state, [RunState::QUEUED, RunState::RUNNING], true)) {
            return true;
        }
        $publishLimit = $this->limits->evaluate($run, [], [['bytes' => strlen($bytes)]], strlen($bytes));
        if ($publishLimit instanceof ImportLimitResult) {
            try {
                $this->humanRequests->openLimitRequest($run, $job, $publishLimit, WaitReason::RESOURCE_LIMIT);
            } catch (HumanRequestRejected $exception) {
                $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::HUMAN_REQUEST_ERROR, $bindings, $exception->reason);
            }

            return true;
        }
        try {
            $artifact = $this->artifacts->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, [
                'role' => AgentRole::FINDING_VERIFICATION->value,
                'slot_id' => $slot->slot_id,
                'round_number' => $job->step_number,
                'attempt' => $attempt,
            ], $context);
            $result = $this->validator->validate($bytes, $agentContext, $context);
            if ($result->status === AgentResultStatus::FAILED) {
                $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error', artifactId: $artifact->id);

                return false;
            }
            if ($result->status === AgentResultStatus::NEEDS_HUMAN && $result->humanRequest !== null) {
                $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::NEEDS_HUMAN, $bindings, resultStatus: $result->status->value, artifactId: $artifact->id);
                try {
                    $this->humanRequests->open($run, $result->humanRequest, $slot->slot_id, $job->idempotency_key);

                    return true;
                } catch (HumanRequestRejected $exception) {
                    $this->results->append($run, $slot, $job->step_number, $attempt + 1, ReviewInvocationOutcome::HUMAN_REQUEST_ERROR, $bindings, $exception->reason);

                    return false;
                }
            }
            $this->results->appendValid($run, $slot, $job->step_number, $attempt, $bindings, $result, $artifact->id, $context);
        } catch (JsonDecodingException|InvalidRedactionInputException|AgentResultValidationException) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::INVALID_JSON, $bindings, 'invalid_verifier_schema');

            return false;
        } catch (Throwable) {
            $this->results->append($run, $slot, $job->step_number, $attempt, ReviewInvocationOutcome::PROVIDER_ERROR, $bindings, 'provider_error');

            return false;
        }
        if (in_array($result->findingVerification?->assessment, [FindingVerificationAssessment::CONTRADICTED, FindingVerificationAssessment::INCONCLUSIVE], true)) {
            $this->openDecision($run, $job, 'finding_verification_review', 'Verifierevidenz benötigt eine menschliche Entscheidung', 'Widerspruch oder ergebnislose Verifikation entfalten keine automatische Wirkung.', 'keep_finding', $slot->slot_id, $finding->criterion_refs);

            return true;
        }

        return false;
    }

    /** @param list<string> $criteria */
    private function openDecision(Run $run, ExecutionJob $job, string $kind, string $title, string $why, string $recommended, string $slotId = 'system:verify', array $criteria = []): void
    {
        try {
            $options = [
                new HumanRequestOption($recommended, $recommended === 'keep_finding' ? 'Finding unverändert wirksam lassen' : 'Run kontrolliert abbrechen und neu freigeben'),
            ];
            if ($recommended === 'keep_finding') {
                $options[] = new HumanRequestOption('switch_profile', 'Anderes unabhängiges Verifierprofil verwenden');
            }
            $this->humanRequests->open($run, new HumanRequestProposal($kind, $title,
                'Die Verifikation bleibt advisory und verändert das Finding nicht.', $why, 'select', $options,
                $recommended, [], $criteria), $slotId, $job->idempotency_key);
        } catch (HumanRequestRejected $exception) {
            $this->fail($job, $run, (string) $job->lease_owner, $exception->reason);
        }
    }

    private function complete(ExecutionJob $job, Run $run, string $owner): void
    {
        if ($this->orchestrator->applyPreparedStepEffect($run->fresh() ?? $run, ExecutionStepType::VERIFY, $job->step_number)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Finding-Verifikation abgeschlossen.');
        }
    }

    /** @return array<string, mixed> */
    private function approvalBindings(Run $run, TicketApproval $approval): array
    {
        foreach (['config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash'] as $field) {
            if (! is_string($run->getAttribute($field)) || ! hash_equals((string) $approval->getAttribute($field), (string) $run->getAttribute($field))) {
                throw new ImplementationImportException('approval_binding_mismatch', 'The run no longer matches its approval snapshot.');
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

    private function prompt(Run $run): PromptSnapshot
    {
        $snapshot = ($run->prompt_snapshot ?? [])['finding_verification_snapshot'] ?? null;
        $binding = ($run->prompt_snapshot ?? [])['finding_verification_prompt_binding'] ?? null;
        $entry = $this->catalog->entry('finding_verification');
        if (! is_array($snapshot) || ! is_array($snapshot['rendered_prompts'] ?? null)
            || ! is_string($snapshot['rendered_prompts']['finding_verification'] ?? null)
            || ! is_string($snapshot['prompt_snapshot_hash'] ?? null)
            || ! is_array($binding) || ($binding['entry_version'] ?? null) !== $entry->version
            || ! is_string($binding['template_sha256'] ?? null)
            || ! hash_equals($binding['template_sha256'], hash('sha256', $entry->template))) {
            throw new ImplementationImportException('verification_prompt_binding_missing', 'The verification prompt is absent from the approval snapshot.');
        }

        return new PromptSnapshot((string) ($snapshot['catalog_version'] ?? '1'), (array) ($snapshot['selected_profiles'] ?? []), $snapshot['rendered_prompts'], $snapshot['prompt_snapshot_hash']);
    }

    private function instruction(Run $run, string $provider): InstructionSnapshot
    {
        $snapshot = ($run->instruction_snapshot ?? [])[$provider] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['entries'] ?? null) || ! is_string($snapshot['instruction_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('instruction_binding_missing', 'The verifier instruction binding is missing.');
        }
        $entries = [];
        foreach ($snapshot['entries'] as $entry) {
            if (! is_array($entry)) {
                throw new ImplementationImportException('instruction_binding_missing', 'The verifier instruction binding is malformed.');
            }
            $entries[] = new InstructionSnapshotEntry((string) ($entry['discovery_name'] ?? ''), (string) ($entry['scope'] ?? ''), (int) ($entry['priority'] ?? 0), (string) ($entry['repository_path'] ?? ''), (string) ($entry['blob_sha'] ?? ''), (string) ($entry['effective_content'] ?? ''), is_array($entry['imports'] ?? null) ? array_values(array_filter($entry['imports'], 'is_string')) : []);
        }

        return new InstructionSnapshot($provider, $entries, $snapshot['instruction_snapshot_hash']);
    }

    private function runtime(Run $run, VerifierCandidate $candidate): ProviderRuntimeProfile
    {
        foreach (($run->agent_profile_snapshot ?? [])['verifier_candidates'] ?? [] as $bound) {
            if (is_array($bound) && ($bound['id'] ?? null) === $candidate->id && is_string($bound['runtime_profile_id'] ?? null)) {
                $runtime = $this->runtimeProfiles->get($bound['runtime_profile_id']);
                $snapshot = ($run->runtime_profile_snapshot ?? [])[$runtime->id] ?? null;
                if (is_array($snapshot) && is_string($snapshot['hash'] ?? null) && hash_equals($runtime->hash, $snapshot['hash'])) {
                    return $runtime;
                }
            }
        }
        throw new ImplementationImportException('runtime_profile_binding_missing', 'The verifier runtime binding is missing.');
    }

    /** @return array{string, string}|null */
    private function roots(): ?array
    {
        $baseInput = config('ai6.execution_mailboxes.agent_root');
        $baseOutput = config('ai6.execution_mailboxes.agent_output_root');
        if (! is_string($baseInput) || $baseInput === '' || ! is_string($baseOutput) || $baseOutput === '') {
            return null;
        }
        foreach ([$baseInput, $baseOutput] as $base) {
            if (! is_dir($base) && ! mkdir($base, 0700, true) && ! is_dir($base)) {
                return null;
            }
        }
        $name = 'verification-'.bin2hex(random_bytes(12));
        $input = rtrim($baseInput, '/\\').DIRECTORY_SEPARATOR.$name;
        $output = rtrim($baseOutput, '/\\').DIRECTORY_SEPARATOR.$name;
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

    private function destroy(?ExecutionHome $home, ?string $io, string ...$paths): bool
    {
        $complete = true;
        if ($home instanceof ExecutionHome) {
            try {
                $this->homes->destroy($home);
            } catch (Throwable) {
                $complete = false;
            }
        }
        foreach (array_filter([$io, ...$paths], 'is_string') as $path) {
            $this->removeTree($path);
        }

        return $complete
            && (! $home instanceof ExecutionHome || (! file_exists($home->root) && ! file_exists($home->outputRoot)))
            && ! array_filter(array_filter([$io, ...$paths], 'is_string'), 'file_exists');
    }

    private function groupHasMultipleFindings(Run $run, Finding $finding): bool
    {
        return Finding::query()->where('run_id', $run->id)
            ->where('checkpoint_tree_sha', $finding->checkpoint_tree_sha)
            ->where('diff_hash', $finding->diff_hash)
            ->where('duplicate_group', $finding->duplicate_group)
            ->count() > 1;
    }

    /** @return list<string> */
    private function groupProviderProfiles(Run $run, Finding $finding): array
    {
        return Finding::query()->where('run_id', $run->id)
            ->where('checkpoint_tree_sha', $finding->checkpoint_tree_sha)
            ->where('diff_hash', $finding->diff_hash)
            ->where('duplicate_group', $finding->duplicate_group)
            ->pluck('provider_profile')->filter(static fn (mixed $value): bool => is_string($value))->unique()->sort()->values()->all();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        @chmod($path, 0700);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    private function fail(ExecutionJob $job, Run $run, string $owner, string $code): void
    {
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Finding-Verifikation fehlgeschlagen.', $code);
        $this->orchestrator->failRun($run->id);
    }

    /** @param array<string, scalar> $expected */
    private function intentMatches(string $stored, array $expected): bool
    {
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return $decoded === $expected;
    }
}
