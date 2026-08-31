<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Agents\SecurityReviewerProfileResolver;
use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\IsolatedTreeExport;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\WorktreeGitMetadataPaths;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\InstructionBindingVerifier;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Support\Str;
use Throwable;

/** Fresh, read-only and candidate-bound pre-commit security review. */
final readonly class SecurityReviewStep
{
    public function __construct(
        private RunOrchestrator $runs,
        private SecurityReviewEvidence $evidence,
        private SecurityReviewerProfileResolver $reviewer,
        private SecurityPolicy $policy,
        private ReviewResultStore $results,
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
        private IsolatedTreeExport $exporter,
        private CheckTreeBinding $trees,
        private ExecutionHomeManager $homes,
        private InstructionBindingVerifier $instructionBindings,
        private InstructionProfileRegistry $instructionProfiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private CredentialRevisionRegistry $credentialRevisions,
        private FakeAgentAdapter $adapter,
        private AgentResultValidator $validator,
        private RunArtifactStore $artifacts,
        private HumanRequestService $humanRequests,
        private WorktreeGitMetadataPaths $gitMetadataPaths,
        private SecurityReviewPrompt $securityPrompt,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        if ($run->phase->value !== 'security_review' || ! $this->candidateComplete($run)) {
            $this->park($job, $run, 'security_candidate_binding_missing');

            return;
        }
        $boundPolicyHash = $run->getAttribute('security_policy_hash');
        if (! is_string($boundPolicyHash)
            || ! hash_equals($this->policy->hash(), $boundPolicyHash)) {
            $this->park($job, $run, 'security_policy_binding_drift');

            return;
        }
        if ($this->evidence->allowsContinuation($run)) {
            $this->succeed($job, $run, $owner);

            return;
        }
        if (! $this->policy->isEnabled(SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW)) {
            RunEvent::query()->firstOrCreate([
                'run_id' => $run->id,
                'event_key' => 'security-review-skipped:'.$run->candidate_tree_sha.':'.$run->security_policy_hash,
            ], [
                'event_type' => 'security_review_skipped',
                'redacted_payload' => 'Der LLM-Sicherheitsreview wurde policybedingt übersprungen; dies ist kein bestandener Sicherheitsnachweis.',
            ]);
            $this->succeed($job, $run, $owner);

            return;
        }

        $profileId = null;
        $instructionHash = null;
        try {
            $selection = $this->reviewer->resolve();
            $profileId = $selection->profile->id;
            if ($selection->profile->adapterId !== 'fake') {
                throw new ImplementationImportException('security_adapter_not_available', 'A real provider adapter is not part of this product state.');
            }
            $approved = ($run->agent_profile_snapshot ?? [])['security_reviewer'] ?? null;
            if (! is_array($approved)
                || ($approved['profile_id'] ?? null) !== $profileId
                || ($approved['provider_profile'] ?? null) !== $selection->profile->providerProfileAlias
                || ($approved['model'] ?? null) !== $selection->model
                || ($approved['effort'] ?? null) !== $selection->effort
                || ($approved['runtime_profile_id'] ?? null) !== $selection->profile->runtimeProfileId) {
                throw new ImplementationImportException('security_reviewer_approval_mismatch', 'The security reviewer differs from the approved profile.');
            }
            $instruction = $this->instruction($run, $selection->profile->providerProfileAlias);
            $instructionHash = $instruction->hash;
            $runtime = $this->runtime($run, $selection->profile->runtimeProfileId);
            $drift = $this->instructionBindings->driftCodeForProfile($run, $selection->profile->providerProfileAlias, $selection->profile->runtimeProfileId);
            if ($drift !== null) {
                throw new ImplementationImportException($drift, 'The security instruction or runtime binding changed.');
            }
            $prompt = $this->securityPrompt->snapshot($run);
            $slot = $this->runs->startSecurityReviewSession($run, $selection, (string) Str::uuid());
            $bindings = $this->bindings($run, $profileId, $prompt, $instruction, $runtime);
            $bytes = $this->invoke($run, $slot, $prompt, $instruction, $runtime, $bindings);
            $context = new RedactionContext((string) $run->project_id, $run->id, 'security-review');
            $artifact = $this->artifacts->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, [
                'role' => AgentRole::SECURITY_REVIEW->value,
                'slot_id' => $slot->slot_id,
                'attempt' => 1,
            ], $context);
            $result = $this->validator->validate($bytes, new AgentResultContext(
                AgentRole::SECURITY_REVIEW, $prompt, $instruction, $runtime, [], '', slotId: $slot->slot_id,
            ), $context);
            try {
                $review = $this->results->appendValid($run, $slot, 1, 1, $bindings, $result, $artifact->id, $context);
            } catch (Throwable) {
                throw new ImplementationImportException('security_result_persistence_failed', 'The bound security result could not be stored.');
            }
            if ($result->status === AgentResultStatus::CLEAR && $this->evidence->validClear($run->fresh() ?? $run)?->id === $review->id) {
                try {
                    $this->succeed($job, $run->fresh() ?? $run, $owner);
                } catch (Throwable) {
                    throw new ImplementationImportException('security_continuation_failed', 'The bound clear could not advance the run.');
                }

                return;
            }
            $critical = $review->findings()->where('severity', FindingSeverity::CRITICAL->value)->exists();
            $this->park($job, $run->fresh() ?? $run, 'security_result_'.$result->status->value, $profileId, $instructionHash, $critical);
        } catch (JsonDecodingException|AgentResultValidationException|InvalidRedactionInputException) {
            $this->park($job, $run->fresh() ?? $run, 'security_result_invalid', $profileId, $instructionHash);
        } catch (Throwable $exception) {
            $reason = $exception instanceof ImplementationImportException
                ? $exception->reason
                : 'security_'.Str::snake(class_basename($exception));
            $this->park($job, $run->fresh() ?? $run, $reason, $profileId, $instructionHash);
        }
    }

    /** Preserve security-gate precedence when a server runtime limit stops this step. */
    public function parkForFailure(ExecutionJob $job, Run $run, string $reason): void
    {
        $this->park($job, $run, $reason);
    }

    /** @param array<string, mixed> $bindings */
    private function invoke(Run $run, RunAgent $slot, PromptSnapshot $prompt, InstructionSnapshot $instruction, ProviderRuntimeProfile $runtime, array &$bindings): string
    {
        $project = $run->project()->firstOrFail();
        if (! is_string($project->project_identifier)) {
            throw new ImplementationImportException('security_workspace_unavailable', 'The managed project binding is missing.');
        }
        [$inputRoot, $outputRoot] = $this->roots();
        $name = 'security-review-'.bin2hex(random_bytes(12));
        $input = $inputRoot.DIRECTORY_SEPARATOR.$name;
        $output = $outputRoot.DIRECTORY_SEPARATOR.$name;
        if (! mkdir($input, 0700) || ! mkdir($output, 0700)) {
            throw new ImplementationImportException('security_workspace_unavailable', 'The security invocation roots are unavailable.');
        }
        try {
            $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($project->project_identifier));
        } catch (Throwable) {
            throw new ImplementationImportException('security_repository_binding_failed', 'The managed repository binding is unavailable.');
        }
        $stage = $input.DIRECTORY_SEPARATOR.'candidate-stage';
        $export = $input.DIRECTORY_SEPARATOR.'candidate-export';
        $context = new RedactionContext((string) $run->project_id, $run->id, 'security-candidate-export');
        $home = null;
        $adapterIo = null;
        try {
            $files = 0;
            $bytes = 0;
            try {
                $this->materializeTree($repository, (string) $run->candidate_tree_sha, $stage, $context, $files, $bytes);
            } catch (ImplementationImportException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new ImplementationImportException('security_candidate_materialization_failed', 'The candidate tree could not be materialized.');
            }
            try {
                $this->exporter->export($stage, $export);
            } catch (Throwable) {
                throw new ImplementationImportException('security_export_failed', 'The candidate export failed.');
            }
            try {
                $bindings['workspace_tree_hash'] = $this->trees->hash($export);
            } catch (Throwable) {
                throw new ImplementationImportException('security_workspace_binding_failed', 'The candidate export could not be bound.');
            }
            try {
                $home = $this->homes->create(
                    $input, $output, $slot->slot_id, $slot->session_id, $export,
                    $this->instructionProfiles->get($slot->provider_profile), $instruction, $runtime,
                    new CredentialProjection($slot->provider_profile, $this->credentialRevisions->revision($slot->provider_profile), []),
                );
            } catch (Throwable) {
                throw new ImplementationImportException('security_home_failed', 'The sealed security home could not be created.');
            }
            $adapterIo = $home->root.'-io';
            try {
                return $this->adapter->turn(
                    new AgentResultContext(AgentRole::SECURITY_REVIEW, $prompt, $instruction, $runtime, [], '', slotId: $slot->slot_id),
                    $home->workspace,
                    $this->gitMetadataPaths->resolve((string) $run->worktree_path),
                );
            } catch (Throwable) {
                throw new ImplementationImportException('security_provider_error', 'The local security adapter failed.');
            }
        } finally {
            if ($home instanceof ExecutionHome) {
                try {
                    $this->homes->destroy($home);
                } catch (Throwable) {
                    throw new ImplementationImportException('security_home_cleanup_failed', 'The security home was not removed completely.');
                }
            }
            if (is_string($adapterIo)) {
                $this->removeTree($adapterIo);
            }
            $this->removeTree($stage);
            $this->removeTree($export);
            $this->removeTree($input);
            $this->removeTree($output);
        }
    }

    private function materializeTree(
        string $repository,
        string $tree,
        string $destination,
        RedactionContext $context,
        int &$files,
        int &$bytes,
        int $depth = 0,
    ): void {
        $fileLimit = (int) config('ai6.process.server_limits.file_count', 1000);
        $byteLimit = (int) config('ai6.process.server_limits.total_bytes', 20000000);
        if ($depth > 64 || ! mkdir($destination, 0700)) {
            throw new ImplementationImportException('security_candidate_materialization_failed', 'The candidate tree is too deep or unavailable.');
        }
        foreach ($this->git->listRunTreeEntries($repository, $tree, $context) as $entry) {
            $path = $destination.DIRECTORY_SEPARATOR.$entry->name;
            if ($entry->type === 'tree') {
                $this->materializeTree($repository, $entry->objectId, $path, $context, $files, $bytes, $depth + 1);

                continue;
            }
            if (! $entry->isRegularBlob() || ++$files > $fileLimit) {
                throw new ImplementationImportException('security_candidate_type_rejected', 'The candidate contains an unsupported entry or exceeds its file limit.');
            }
            $remaining = $byteLimit - $bytes;
            if ($remaining < 1) {
                throw new ImplementationImportException('security_candidate_size_exceeded', 'The candidate exceeds its byte limit.');
            }
            $content = $this->git->readRunBlob($repository, $entry->objectId, $remaining, $context);
            $bytes += strlen($content);
            if ($bytes > $byteLimit || file_put_contents($path, $content, LOCK_EX) !== strlen($content)
                || ! chmod($path, $entry->mode === '100755' ? 0500 : 0400)) {
                throw new ImplementationImportException('security_candidate_materialization_failed', 'The candidate blob could not be materialized safely.');
            }
        }
        if (! chmod($destination, 0500)) {
            throw new ImplementationImportException('security_candidate_materialization_failed', 'The candidate tree could not be sealed.');
        }
    }

    /** @return array<string, mixed> */
    private function bindings(Run $run, string $profileId, PromptSnapshot $prompt, InstructionSnapshot $instruction, ProviderRuntimeProfile $runtime): array
    {
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        foreach (['config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash'] as $field) {
            $runValue = $run->getAttribute($field);
            $approvalValue = $approval->getAttribute($field);
            if (! is_string($runValue) || ! is_string($approvalValue) || ! hash_equals($runValue, $approvalValue)) {
                throw new ImplementationImportException('security_approval_binding_mismatch', 'The candidate no longer matches its approval.');
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
            'slot_prompt_hash' => $prompt->hash,
            'slot_instruction_hash' => $instruction->hash,
            'slot_runtime_profile_hash' => $runtime->hash,
            'workspace_tree_hash' => null,
            'candidate_tree_sha' => $run->candidate_tree_sha,
            'candidate_diff_hash' => $run->candidate_diff_hash,
            'candidate_base_sha' => $run->candidate_base_sha,
            'candidate_ticket_contract_sha256' => $run->candidate_ticket_contract_sha256,
            'candidate_scope_hash' => $run->candidate_scope_hash,
            'candidate_prompt_snapshot_hash' => $prompt->hash,
            'candidate_instruction_snapshot_hash' => $instruction->hash,
            'candidate_agent_profile_id' => $profileId,
            'candidate_runtime_profile_hash' => $runtime->hash,
            'candidate_security_policy_hash' => $run->security_policy_hash,
        ];
    }

    private function instruction(Run $run, string $provider): InstructionSnapshot
    {
        $snapshot = ($run->instruction_snapshot ?? [])[$provider] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['entries'] ?? null) || ! is_string($snapshot['instruction_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('instruction_binding_missing', 'The security instruction binding is missing.');
        }
        $entries = [];
        foreach ($snapshot['entries'] as $entry) {
            if (! is_array($entry)) {
                throw new ImplementationImportException('instruction_binding_missing', 'The security instruction binding is malformed.');
            }
            $value = new InstructionSnapshotEntry(
                (string) ($entry['discovery_name'] ?? ''), (string) ($entry['scope'] ?? ''), (int) ($entry['priority'] ?? 0),
                (string) ($entry['repository_path'] ?? ''), (string) ($entry['blob_sha'] ?? ''), (string) ($entry['effective_content'] ?? ''),
                is_array($entry['imports'] ?? null) ? array_values(array_filter($entry['imports'], 'is_string')) : [],
            );
            if (($entry['content_sha256'] ?? null) !== $value->contentSha256) {
                throw new ImplementationImportException('instruction_binding_mismatch', 'The security instruction bytes changed.');
            }
            $entries[] = $value;
        }

        return new InstructionSnapshot($provider, $entries, $snapshot['instruction_snapshot_hash']);
    }

    private function runtime(Run $run, string $runtimeId): ProviderRuntimeProfile
    {
        $bound = ($run->runtime_profile_snapshot ?? [])[$runtimeId] ?? null;
        if (! is_array($bound) || ! is_string($bound['hash'] ?? null)) {
            throw new ImplementationImportException('runtime_profile_binding_missing', 'The security runtime binding is missing.');
        }
        $runtime = $this->runtimeProfiles->get($runtimeId);
        if (! hash_equals($runtime->hash, $bound['hash'])) {
            throw new ImplementationImportException('runtime_profile_drift', 'The security runtime binding changed.');
        }

        return $runtime;
    }

    private function candidateComplete(Run $run): bool
    {
        foreach (['candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha', 'candidate_ticket_contract_sha256', 'candidate_scope_hash'] as $field) {
            if (! is_string($value = $run->getAttribute($field)) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                return false;
            }
        }

        return $run->candidate_invalidated_at === null;
    }

    private function succeed(ExecutionJob $job, Run $run, string $owner): void
    {
        if ($this->runs->applyPreparedStepEffect($run, ExecutionStepType::SECURITY_REVIEW, $job->step_number)) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Sicherheitsgate abgeschlossen.');
        }
    }

    private function park(ExecutionJob $job, Run $run, string $reason, ?string $profileId = null, ?string $instructionHash = null, bool $overrideAllowed = false): void
    {
        $profileId ??= $this->boundProfileId($run);
        $instructionHash ??= $this->boundInstructionHash($run, $profileId);
        if ($profileId !== null && $instructionHash !== null && $this->candidateComplete($run)) {
            try {
                $this->humanRequests->openSecurityGateRequest($run, $job, $profileId, $instructionHash, $reason, $overrideAllowed);

                return;
            } catch (HumanRequestRejected) {
            }
        }
        try {
            $fresh = $run->fresh() ?? $run;
            if ($fresh->state === RunState::RUNNING) {
                $this->runs->transition($fresh, $fresh->version, RunState::WAITING, $fresh->phase, WaitReason::SECURITY_GATE);
            }
        } catch (Throwable) {
        }
        $this->runs->parkStep($job, (string) $job->lease_owner);
    }

    private function boundProfileId(Run $run): ?string
    {
        $value = ($run->agent_profile_snapshot ?? [])['security_reviewer']['profile_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function boundInstructionHash(Run $run, ?string $profileId): ?string
    {
        $security = ($run->agent_profile_snapshot ?? [])['security_reviewer'] ?? null;
        if (! is_array($security) || ($security['profile_id'] ?? null) !== $profileId
            || ! is_string($provider = $security['provider_profile'] ?? null)) {
            return null;
        }
        $snapshot = ($run->instruction_snapshot ?? [])[$provider] ?? null;

        return is_array($snapshot) && is_string($snapshot['instruction_snapshot_hash'] ?? null) ? $snapshot['instruction_snapshot_hash'] : null;
    }

    /** @return array{string, string} */
    private function roots(): array
    {
        $input = config('ai6.execution_mailboxes.agent_root');
        $output = config('ai6.execution_mailboxes.agent_output_root');
        if (! is_string($input) || ! is_string($output) || $input === '' || $output === '' || $input === $output) {
            throw new ImplementationImportException('security_workspace_unavailable', 'The security execution roots are unavailable.');
        }
        foreach ([$input, $output] as $root) {
            if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
                throw new ImplementationImportException('security_workspace_unavailable', 'The security execution root cannot be created.');
            }
        }

        return [$input, $output];
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
}
