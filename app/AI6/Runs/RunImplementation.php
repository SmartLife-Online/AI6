<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResult;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultImporter;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidationError;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Agents\ImplementationDecision;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\RunPatchChange;
use App\AI6\Git\RunPatchImporter;
use App\AI6\Git\RunPatchStatus;
use App\AI6\Git\WorktreeGitMetadataPaths;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\ScopeApprovalService;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** The registered implement-step handler. Side effects stay behind the existing import seams. */
final readonly class RunImplementation
{
    public function __construct(
        private RunOrchestrator $orchestrator,
        private InstructionBindingVerifier $bindings,
        private IsolatedTreeExporter $exporter,
        private RunPatchImporter $patches,
        private AgentResultImporter $results,
        private AgentAdapter $adapter,
        private RunLimitPolicy $limits,
        private RunArtifactStore $artifacts,
        private HumanRequestService $humanRequests,
        private ScopeApprovalService $scopeApprovals,
        private EffectiveProjectConfiguration $projectConfiguration,
        private Redactor $redactor,
        private CanonicalJson $canonicalJson,
        private TicketV1Parser $tickets,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private InstructionPathPolicy $instructionPaths,
        private WorktreeGitMetadataPaths $gitMetadataPaths,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        try {
            $slot = $this->orchestrator->ensureImplementationSlot($run);
        } catch (ImplementationImportException $exception) {
            $this->failNamed($job, $run, $owner, $exception->reason, $exception->getMessage());

            return;
        }

        $drift = $this->bindings->driftCode($run);
        if ($drift !== null) {
            $this->orchestrator->discardImplementationSessions($run);
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Instruktions- oder Runtimebindung ist veraltet: '.$drift.'.', $drift);
            $this->orchestrator->failRun($run->id);

            return;
        }

        $failure = $this->orchestrator->preflightFailureCode($run);
        if ($failure !== null) {
            if (in_array($failure, ['runtime_profile_not_server_bound', 'snapshot_binding_changed', 'instruction_binding_missing'], true)) {
                $this->orchestrator->discardImplementationSessions($run);
            }
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Preflight ist fehlgeschlagen: '.$failure.'.', $failure);
            $this->orchestrator->failRun($run->id);

            return;
        }

        $intent = $this->boundIntent($run);
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                return;
            }
        } elseif (! $this->intentMatches($job->intent, $intent)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Schritt-Intent ist nicht gebunden.', 'invalid_step_intent');
            $this->orchestrator->failRun($run->id);

            return;
        }

        $sessionId = $slot->session_id ?? (string) Str::uuid();
        $this->orchestrator->bindImplementationSession($run, $slot->slot_id, $sessionId);

        $worktree = $run->worktree_path;
        if (! is_string($worktree) || ! is_dir($worktree) || is_link($worktree)) {
            $this->failNamed($job, $run, $owner, 'workspace_unavailable', 'Der gebundene Run-Worktree ist nicht verfügbar.');

            return;
        }

        $resolvedRoot = $this->agentWorkingRoot();
        if ($resolvedRoot === null) {
            $this->failNamed($job, $run, $owner, 'isolated_export_unavailable', 'Das isolierte Exportziel ist nicht verfügbar.');

            return;
        }
        $batch = $resolvedRoot.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8));
        if (! is_dir($batch) && ! mkdir($batch, 0700) && ! is_dir($batch)) {
            $this->failNamed($job, $run, $owner, 'isolated_export_unavailable', 'Das isolierte Exportziel ist nicht verfügbar.');

            return;
        }
        $resolvedBatch = realpath($batch);
        if (! is_string($resolvedBatch)) {
            $this->failNamed($job, $run, $owner, 'isolated_export_unavailable', 'Das isolierte Exportziel ist nicht verfügbar.');

            return;
        }
        $isolated = str_replace('\\', '/', $resolvedBatch).'/tree';
        $context = new RedactionContext((string) $run->project_id, $run->id, 'implementation-turn');

        try {
            $this->exporter->export($worktree, $isolated, true);
            $prompt = $this->boundPrompt($run);
            $instruction = $this->boundInstruction($run);
            $runtime = $this->boundRuntime($run);
            $instructionUpdate = $this->isInstructionUpdate($run, $instruction);
            $agentContext = new AgentResultContext(
                AgentRole::IMPLEMENTATION,
                $prompt,
                $instruction,
                $runtime,
                $this->criterionRefs($run),
                '',
                $instructionUpdate,
                $this->initialScope($run),
                $this->expectedInstructionBlobs($instruction, $this->initialScope($run)),
            );
            $bytes = $this->adapter->turn($agentContext, $isolated, $this->gitMetadataPaths->resolve($worktree));
            $this->handleResult($job, $run, $owner, $slot, $isolated, $bytes, $agentContext, $context, $instructionUpdate);
        } catch (ImplementationImportException $exception) {
            $this->failNamed($job, $run, $owner, $exception->reason, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failNamed($job, $run, $owner, 'implementation_turn_failed', $exception::class.': '.$exception->getMessage());
        } finally {
            $this->removeTree($isolated);
            $this->removeTree(dirname($isolated));
        }
    }

    private function handleResult(
        ExecutionJob $job,
        Run $run,
        string $owner,
        RunAgent $slot,
        string $isolated,
        string $bytes,
        AgentResultContext $agentContext,
        RedactionContext $context,
        bool $instructionUpdate,
    ): void {
        try {
            $validated = $this->results->validate($bytes, $agentContext, $context);
        } catch (JsonDecodingException) {
            $this->failNamed($job, $run, $owner, 'invalid_json', 'Das Providerergebnis ist kein gültiges JSON.');

            return;
        } catch (AgentResultValidationException $exception) {
            $this->failNamed($job, $run, $owner, $exception->reason->value, 'Das Providerergebnis ist schemaungültig.');

            return;
        } catch (InvalidRedactionInputException) {
            $this->failNamed($job, $run, $owner, 'invalid_json', 'Das Providerergebnis ist kein gültiges UTF-8.');

            return;
        }

        if ($validated->status === AgentResultStatus::FAILED) {
            $this->failNamed($job, $run, $owner, 'provider_error', 'Der Providerturn ist fehlgeschlagen.');

            return;
        }

        $run = Run::query()->findOrFail($run->getKey());
        if ($instructionUpdate && $validated->instructionPatch !== null) {
            try {
                $this->importInstructionReplacement($run, $isolated, $validated);
            } catch (Throwable $exception) {
                $this->failNamed($job, $run, $owner, 'instruction_patch_rejected', $exception->getMessage());

                return;
            }
        }

        try {
            $partition = $this->patches->partition($run, $isolated, $this->effectiveScope($run));
        } catch (RuntimeException $exception) {
            $this->failNamed($job, $run, $owner, $this->importFailureCode($exception), $exception->getMessage());

            return;
        }
        $changes = [...$partition['in'], ...$partition['out']];
        usort($changes, static fn (RunPatchChange $left, RunPatchChange $right): int => strcmp($left->path, $right->path));

        $actualPaths = array_map(static fn (RunPatchChange $change): string => $change->path, $changes);
        sort($actualPaths);
        $reported = $validated->changedPaths;
        sort($reported);
        if ($actualPaths !== $reported) {
            $this->failNamed($job, $run, $owner, 'reported_path_mismatch', 'Gemeldete und tatsächliche Pfade weichen voneinander ab.');

            return;
        }

        $actualDiff = $this->serverDiff($changes);
        $boundContext = new AgentResultContext(
            $agentContext->role,
            $agentContext->promptSnapshot,
            $agentContext->instructionSnapshot,
            $agentContext->runtimeProfile,
            $agentContext->criterionRefs,
            $actualDiff,
            $agentContext->instructionUpdate,
            $agentContext->initialScope,
            $agentContext->expectedInstructionBlobs,
        );
        try {
            $validated = $this->results->validate($bytes, $boundContext, $context);
        } catch (AgentResultValidationException $exception) {
            $code = $exception->reason === AgentResultValidationError::NO_CHANGE_DIFF
                ? 'no_change_diff'
                : $exception->reason->value;
            $this->failNamed($job, $run, $owner, $code, 'Das Providerergebnis ist schemaungültig.');

            return;
        }

        if ($validated->status === AgentResultStatus::NO_CHANGE_REQUIRED && trim($validated->summary) === '') {
            $this->failNamed($job, $run, $owner, 'no_change_reason_missing', 'no_change_required verlangt eine konkrete Begründung.');

            return;
        }

        $artifactCandidates = $this->artifactCandidates($validated, $bytes);
        $limit = $this->limits->evaluate($run, $partition['in'], $artifactCandidates, strlen($bytes));
        if ($limit instanceof ImportLimitResult) {
            $this->openResourceLimit($job, $run, $slot, $limit);

            return;
        }

        if ($validated->status === AgentResultStatus::NEEDS_HUMAN) {
            $this->openHumanQuestion($run, $slot, $job, $validated);

            return;
        }

        if ($partition['out'] !== []) {
            $resolved = $this->resolveOutOfScopeChanges($job, $run, $owner, $slot, $isolated, $partition['out'], $context);
            if (! $resolved) {
                return;
            }
            // Approved paths joined the effective scope; recompute the split so
            // they import through the same seam, and only rejected changes stay
            // quarantined and without effect (AC-01, AC-06).
            $run = Run::query()->findOrFail($run->getKey());
            try {
                $partition = $this->patches->partition($run, $isolated, $this->effectiveScope($run));
            } catch (RuntimeException $exception) {
                $this->failNamed($job, $run, $owner, $this->importFailureCode($exception), $exception->getMessage());

                return;
            }
            // The freigegebene change limits are evaluated over the set that is
            // actually imported. Approved or auto-allowed paths only join that
            // set here, so the limits run once more over the re-partitioned
            // change set before any write; otherwise a path taken up in this
            // very turn would never count against max_changed_files or
            // max_changed_bytes (RUN-006).
            $limit = $this->limits->evaluate($run, $partition['in'], $artifactCandidates, strlen($bytes));
            if ($limit instanceof ImportLimitResult) {
                $this->openResourceLimit($job, $run, $slot, $limit);

                return;
            }
        }

        $imported = $partition['in'];
        if ($imported !== []) {
            try {
                $this->patches->importChanges($run, $isolated, $imported);
            } catch (RuntimeException $exception) {
                $this->failNamed($job, $run, $owner, $this->importFailureCode($exception), $exception->getMessage());

                return;
            }
        }

        $run = Run::query()->findOrFail($run->getKey());
        $this->orchestrator->bindActualChangedPaths(
            $run,
            $run->version,
            array_map(static fn (RunPatchChange $change): string => $change->path, $imported),
            $this->canonicalJson,
        );

        $this->persistOutcome($run, $validated, $bytes, $imported, $context);
        $this->orchestrator->recordStepEvent($run->id, ExecutionStepType::IMPLEMENT->value, ExecutionJobState::SUCCEEDED, 'Implementierung abgeschlossen.');
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Implementierung abgeschlossen.');
        $this->orchestrator->applyPreparedStepEffect($run->fresh() ?? $run, ExecutionStepType::IMPLEMENT);
    }

    /**
     * Route every changed path outside the effective scope into the one
     * deterministic scope policy (TKT-007).
     *
     * Each undecided path is quarantined first: the proposed change never
     * reaches the run worktree and survives as a redacted artifact. An
     * auto-allowed path joins the effective scope without a decision, the
     * first path that needs one parks the step behind exactly one
     * scope_approval request, and an already rejected path stays quarantined
     * and without effect. Returns true when no decision is pending any more.
     *
     * @param  list<RunPatchChange>  $outOfScope
     */
    private function resolveOutOfScopeChanges(
        ExecutionJob $job,
        Run $run,
        string $owner,
        RunAgent $slot,
        string $isolated,
        array $outOfScope,
        RedactionContext $context,
    ): bool {
        $snapshotId = ($run->config_snapshot ?? [])['snapshot_id'] ?? null;
        if ($snapshotId !== null && ! is_string($snapshotId)) {
            $this->failNamed($job, $run, $owner, 'check_snapshot_invalid', 'Der gebundene Konfigurations-Snapshot ist ungültig.');

            return false;
        }
        $configuration = $this->projectConfiguration
            ->bound($run->project()->firstOrFail(), $snapshotId)
            ->configuration;
        foreach ($outOfScope as $change) {
            $decision = ScopeDecision::query()
                ->where('run_id', $run->getKey())
                ->where('path', $change->path)
                ->first();
            if ($decision instanceof ScopeDecision && $decision->outcome === 'rejected') {
                // Only the AI6-own change is dropped; the quarantined artifact
                // and the decision stay as the audit trail (AC-06).
                $this->orchestrator->recordStepEvent(
                    $run->id,
                    ExecutionStepType::IMPLEMENT->value,
                    ExecutionJobState::RUNNING,
                    'Abgelehnter Zusatzpfad wurde nicht übernommen.',
                    'scope-rejected:'.$run->id.':'.hash('sha256', $change->path),
                );

                continue;
            }

            // The decision later resolves by the request's stored path. A path
            // the redactor would alter cannot round-trip through that binding;
            // failing named beats an unresolvable request loop.
            if ($this->redactor->redact($change->path, $context)->text !== $change->path) {
                $this->failNamed($job, $run, $owner, 'scope_path_not_bindable', 'Der Zusatzpfad kann nicht verlustfrei an eine Entscheidung gebunden werden.');

                return false;
            }

            $this->quarantine($run, $isolated, $change, $context);
            try {
                $request = $this->scopeApprovals->requestOrAutoAllow(
                    Run::query()->findOrFail($run->getKey()),
                    $configuration,
                    $change->path,
                    $change->status === RunPatchStatus::DELETED,
                    $slot->slot_id,
                    $job->idempotency_key,
                );
            } catch (HumanRequestRejected $rejected) {
                if ($rejected->reason === 'open_request_exists') {
                    $this->orchestrator->parkBoundStep($job);

                    return false;
                }
                $this->failNamed($job, $run, $owner, $rejected->reason, $rejected->getMessage());

                return false;
            }
            if ($request !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserve an out-of-scope change as a redacted artifact before any
     * decision (AC-06). The proposed bytes never touch the run worktree.
     */
    private function quarantine(Run $run, string $isolated, RunPatchChange $change, RedactionContext $context): void
    {
        $payload = [
            'path' => $change->path,
            'change' => $change->status->value,
            'bytes' => $change->bytes,
        ];
        if ($change->status !== RunPatchStatus::DELETED) {
            $bytes = file_get_contents(rtrim($isolated, '/\\').'/'.$change->path);
            if (is_string($bytes)) {
                $payload['content_sha256'] = hash('sha256', $bytes);
                if (preg_match('//u', $bytes) === 1) {
                    $payload['content'] = $bytes;
                }
            }
        }
        $this->artifacts->store(
            $run,
            RunArtifactKind::QUARANTINED_PATH,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            [
                'kind' => RunArtifactKind::QUARANTINED_PATH->value,
                'path' => $change->path,
                'change' => $change->status->value,
            ],
            $context,
        );
    }

    /**
     * The bound effective scope: initial scope plus approved exact paths.
     *
     * @return list<string>
     */
    private function effectiveScope(Run $run): array
    {
        $effective = $run->effective_scope_snapshot;
        if (is_array($effective) && $effective !== []) {
            return array_values(array_filter($effective, 'is_string'));
        }

        return $this->approvedScope($run);
    }

    private function openHumanQuestion(Run $run, RunAgent $slot, ExecutionJob $job, AgentResult $result): void
    {
        $proposal = $result->humanRequest ?? new HumanRequestProposal(
            'clarification',
            'Rückfrage',
            $result->summary,
            $result->summary,
            'select',
            [],
            null,
            [],
            [],
        );
        try {
            $this->humanRequests->open($run, $proposal, $slot->slot_id, $job->idempotency_key, WaitReason::HUMAN_QUESTION);
        } catch (HumanRequestRejected $rejected) {
            $this->failNamed($job, $run, (string) $job->lease_owner, $rejected->reason, $rejected->getMessage());
        }
    }

    private function openResourceLimit(ExecutionJob $job, Run $run, RunAgent $slot, ImportLimitResult $limit): void
    {
        $proposal = new HumanRequestProposal(
            'resource_limit',
            'Ressourcenlimit überschritten',
            'Ein freigegebenes Importlimit wurde überschritten.',
            'Ohne Reduktion oder Erhöhung bis zum Servermaximum darf nichts importiert werden.',
            'select',
            [
                new HumanRequestOption('reduce', 'Reduzieren'),
                new HumanRequestOption('increase', 'Erhöhen bis Servermaximum'),
            ],
            'reduce',
            [],
            [],
        );
        try {
            $this->humanRequests->open(
                $run,
                $proposal,
                $slot->slot_id,
                $job->idempotency_key,
                WaitReason::RESOURCE_LIMIT,
                $limit,
            );
        } catch (HumanRequestRejected $rejected) {
            if ($rejected->reason === 'open_request_exists') {
                $this->orchestrator->parkBoundStep($job);

                return;
            }
            $this->failNamed($job, $run, (string) $job->lease_owner, $rejected->reason, $rejected->getMessage());
        }
    }

    /**
     * @param  list<RunPatchChange>  $changes
     */
    private function persistOutcome(Run $run, AgentResult $result, string $bytes, array $changes, RedactionContext $context): void
    {
        $rendered = (string) (($run->prompt_snapshot ?? [])['rendered_prompts']['implementation'] ?? '');
        $summary = [
            'status' => $result->status->value,
            'summary' => $result->summary,
            'decisions' => array_map(
                static fn (ImplementationDecision $decision): array => [
                    'key' => $decision->key,
                    'title' => $decision->title,
                    'rationale' => $decision->rationale,
                ],
                $result->decisions,
            ),
            'changed_files' => array_map(
                static fn (RunPatchChange $change): array => [
                    'path' => $change->path,
                    'change' => $change->status->value,
                    'bytes' => $change->bytes,
                ],
                $changes,
            ),
            'implementation_summary' => $result->implementationSummary === null ? null : [
                'changed_components' => $result->implementationSummary->changedComponents,
                'decisions' => $result->implementationSummary->decisions,
                'assumptions' => $result->implementationSummary->assumptions,
                'deviations' => $result->implementationSummary->deviations,
                'known_limits' => $result->implementationSummary->knownLimits,
                'tests' => $result->implementationSummary->tests,
                'review_focus' => $result->implementationSummary->reviewFocus,
            ],
            'prompt_snapshot_hash' => $run->prompt_hash,
            'rendered_implementation_prompt_sha256' => hash('sha256', $rendered),
        ];
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->artifacts->store($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, $encoded, [
            'kind' => RunArtifactKind::IMPLEMENTATION_SUMMARY->value,
            'changed_file_count' => count($changes),
        ], $context);
        $this->artifacts->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, [
            'kind' => RunArtifactKind::PROVIDER_RAW->value,
        ], $context);
    }

    private function importInstructionReplacement(Run $run, string $isolated, AgentResult $result): void
    {
        $patch = $result->instructionPatch;
        if ($patch === null) {
            return;
        }
        if ($this->scopeAddsInstructionPath($run, $patch->path)) {
            throw new ImplementationImportException('instruction_scope_extension_forbidden', 'An instruction path cannot be added to the scope after approval.');
        }
        $target = rtrim($isolated, '/\\').'/'.$patch->path;
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ImplementationImportException('instruction_patch_rejected', 'The instruction replacement could not be staged.');
        }
        if (file_put_contents($target, $patch->content, LOCK_EX) !== strlen($patch->content)) {
            throw new ImplementationImportException('instruction_patch_rejected', 'The instruction replacement could not be staged.');
        }
    }

    private function scopeAddsInstructionPath(Run $run, string $path): bool
    {
        $initial = $this->initialScope($run);

        return ! in_array($path, $initial, true);
    }

    private function boundPrompt(Run $run): PromptSnapshot
    {
        $snapshot = $run->prompt_snapshot ?? [];
        $rendered = $snapshot['rendered_prompts'] ?? null;
        $hash = $snapshot['prompt_snapshot_hash'] ?? $run->prompt_hash;
        if (! is_array($rendered) || ! is_string($rendered['implementation'] ?? null) || ! is_string($hash) || $hash === '') {
            throw new ImplementationImportException('prompt_binding_missing', 'The bound prompt snapshot is unavailable.');
        }
        $expected = hash('sha256', "AI6-APPROVAL-PROMPTS-V1\0".$this->canonicalJson->normalizeAndEncode($snapshot));
        if (! hash_equals((string) $run->prompt_hash, $expected)) {
            throw new ImplementationImportException('prompt_binding_mismatch', 'The bound prompt snapshot does not match its approval hash.');
        }

        return new PromptSnapshot(
            is_string($snapshot['catalog_version'] ?? null) ? $snapshot['catalog_version'] : '1',
            is_array($snapshot['selected_profiles'] ?? null) ? $snapshot['selected_profiles'] : [],
            $rendered,
            $hash,
        );
    }

    private function boundInstruction(Run $run): InstructionSnapshot
    {
        $provider = ($run->agent_profile_snapshot ?? [])['implementation']['provider_profile'] ?? null;
        $bound = is_string($provider) ? (($run->instruction_snapshot ?? [])[$provider] ?? null) : null;
        if (! is_array($bound) || ! is_string($bound['instruction_snapshot_hash'] ?? null)) {
            throw new ImplementationImportException('instruction_binding_missing', 'The bound instruction snapshot is unavailable.');
        }
        $entries = [];
        foreach ($bound['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entries[] = new InstructionSnapshotEntry(
                (string) ($entry['discovery_name'] ?? 'agent'),
                (string) ($entry['scope'] ?? 'repository'),
                (int) ($entry['priority'] ?? 1),
                (string) ($entry['repository_path'] ?? ''),
                (string) ($entry['blob_sha'] ?? str_repeat('0', 40)),
                (string) ($entry['effective_content'] ?? ''),
                is_array($entry['imports'] ?? null) ? array_values(array_filter($entry['imports'], 'is_string')) : [],
            );
        }

        return new InstructionSnapshot((string) $provider, $entries, $bound['instruction_snapshot_hash']);
    }

    private function boundRuntime(Run $run): ProviderRuntimeProfile
    {
        $runtimeId = ($run->agent_profile_snapshot ?? [])['implementation']['runtime_profile_id'] ?? null;
        $bound = is_string($runtimeId) ? (($run->runtime_profile_snapshot ?? [])[$runtimeId] ?? null) : null;
        if (! is_array($bound) || ! is_string($bound['hash'] ?? null)) {
            throw new ImplementationImportException('runtime_profile_binding_missing', 'The bound runtime profile is unavailable.');
        }
        try {
            $registered = $this->runtimeProfiles->get((string) $runtimeId);
        } catch (Throwable) {
            throw new ImplementationImportException('runtime_profile_not_server_bound', 'The bound runtime profile is not registered.');
        }
        if (! hash_equals($registered->hash, $bound['hash'])) {
            throw new ImplementationImportException('runtime_profile_drift', 'The bound runtime profile does not match the server registry.');
        }

        return $registered;
    }

    /** @return list<string> */
    private function initialScope(Run $run): array
    {
        $files = ($run->scope_snapshot ?? [])['ticket_files'] ?? [];

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    /** @return list<string> */
    private function approvedScope(Run $run): array
    {
        $scope = $this->initialScope($run);
        if ($scope === []) {
            throw new ImplementationImportException('scope_binding_missing', 'The run has no bound approved scope.');
        }

        return $scope;
    }

    /** @return list<string> */
    private function criterionRefs(Run $run): array
    {
        $approval = TicketApproval::query()->whereKey($run->ticket_approval_id)->first();
        if (! $approval instanceof TicketApproval) {
            return [];
        }
        $readModel = TicketReadModel::query()
            ->where('project_id', $run->project_id)
            ->where('relative_path', $approval->relative_path)
            ->first();
        if (! $readModel instanceof TicketReadModel) {
            return [];
        }
        try {
            return $this->tickets->parse($readModel->redacted_content)->acceptanceCriterionIds;
        } catch (TicketParseException) {
            return [];
        }
    }

    /**
     * @param  list<string>  $initialScope
     * @return array<string, string|null>
     */
    private function expectedInstructionBlobs(InstructionSnapshot $snapshot, array $initialScope): array
    {
        $blobs = [];
        foreach ($snapshot->entries as $entry) {
            $blobs[$entry->repositoryPath] = $entry->blobSha;
        }
        foreach ($initialScope as $path) {
            if (! array_key_exists($path, $blobs) && $this->looksLikeInstruction($path)) {
                $blobs[$path] = null;
            }
        }

        return $blobs;
    }

    private function looksLikeInstruction(string $path): bool
    {
        return $this->instructionPaths->isInstructionPath($path);
    }

    private function isInstructionUpdate(Run $run, InstructionSnapshot $snapshot): bool
    {
        $scope = $this->initialScope($run);
        foreach ($snapshot->entries as $entry) {
            if (in_array($entry->repositoryPath, $scope, true)) {
                return true;
            }
        }

        return in_array('AGENTS.md', $scope, true) || in_array('CLAUDE.md', $scope, true);
    }

    /**
     * @return list<array{bytes: int}>
     */
    private function artifactCandidates(AgentResult $result, string $bytes): array
    {
        $candidates = [['bytes' => strlen($bytes)]];
        if ($result->implementationSummary !== null) {
            $candidates[] = ['bytes' => strlen((string) json_encode($result->implementationSummary, JSON_UNESCAPED_SLASHES))];
        }

        return $candidates;
    }

    /** @param  list<RunPatchChange>  $changes */
    private function serverDiff(array $changes): string
    {
        if ($changes === []) {
            return '';
        }

        return implode("\n", array_map(
            static fn (RunPatchChange $change): string => $change->status->value.' '.$change->path,
            $changes,
        ));
    }

    private function importFailureCode(RuntimeException $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'Git metadata')) {
            return 'git_metadata_rejected';
        }
        if (str_contains($message, 'symbolic link')) {
            return 'symlink_rejected';
        }
        if (str_contains($message, 'binary')) {
            return 'binary_rejected';
        }
        if (str_contains($message, 'rollback')) {
            return 'import_rollback_failed';
        }
        if (str_contains($message, 'size limit') || str_contains($message, 'change limit') || str_contains($message, 'total size')) {
            return 'import_limit_exceeded';
        }
        if (str_contains($message, 'outside the approved scope')) {
            return 'scope_violation';
        }

        return 'import_rejected';
    }

    private function failNamed(ExecutionJob $job, Run $run, string $owner, string $code, string $message): void
    {
        $redacted = $this->redactor->redact($message, new RedactionContext((string) $run->project_id, $run->id, 'implementation-failure'));
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, $redacted->text, $code);
        $this->orchestrator->failRun($run->id);
    }

    /** @return array<string, scalar> */
    private function boundIntent(Run $run): array
    {
        return [
            'effect' => 'import_implementation_result',
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::IMPLEMENT->value,
            'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::IMPLEMENT, 1),
        ];
    }

    /** @param  array<string, scalar>  $expected */
    private function intentMatches(string $stored, array $expected): bool
    {
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $decoded === $expected;
    }

    private function agentWorkingRoot(): ?string
    {
        $roots = config('ai6.process.policies.agent.working_roots');
        $configured = is_array($roots) ? ($roots[0] ?? null) : null;
        if (! is_string($configured) || $configured === '') {
            return null;
        }
        if (! is_dir($configured) && ! mkdir($configured, 0700, true) && ! is_dir($configured)) {
            return null;
        }
        $resolved = realpath($configured);

        return is_string($resolved) ? $resolved : null;
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || ! is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
