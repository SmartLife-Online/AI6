<?php

namespace App\AI6\Agents;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\MailboxRejectedException;
use App\AI6\Shared\Process\MailboxRejection;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** The worker owns staging, polling, response artifacts and terminal cleanup. */
final class AgentExecutionRunner
{
    /**
     * The bridge between remember() and the same turn's later
     * persistAnswer()/store() call, within one synchronous request. This
     * binding is a singleton across the whole worker process, so the map is
     * capped rather than left to grow for the process's entire lifetime.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $answerMetadata = [];

    /**
     * A repeated store() call for the same turn's bytes intentionally omits
     * fields persistAnswer() already bound (callers pass only what they know),
     * so a fresh RunArtifactStore::store() call with that narrower metadata
     * would read as a changed binding. This cache keeps store() idempotent
     * across such calls within one turn; a hit is still re-checked against
     * the persisted row's tombstone before being trusted (AI6-047 finding).
     *
     * @var array<string, RunArtifact>
     */
    private array $answerArtifacts = [];

    private const MAX_REMEMBERED_ANSWERS = 32;

    public function __construct(
        private readonly ExecutionHomeManager $homes,
        private readonly InstructionProfileRegistry $instructions,
        private readonly CredentialRevisionRegistry $revisions,
        private readonly ExecutionMailboxFactory $mailboxes,
        private readonly ProcessPolicyRegistry $policies,
        private readonly SecurityPolicy $security,
        private readonly RunOrchestrator $runs,
        private readonly RunArtifactStore $artifacts,
        private readonly RestrictedJsonDecoder $json,
        private readonly Redactor $redactor,
        private readonly RunLimitPolicy $limits,
    ) {}

    public function mayExecuteHere(): bool
    {
        return config('ai6.runtime_role') === ExecutionRole::AGENT->value
            || ($this->security->profile !== SecurityProfile::STRICT && $this->security->reducedModeAcknowledged
                && ! $this->security->isEnabled(SecurityMeasure::REQUIRE_AGENT_SANDBOX));
    }

    public function prepare(ExecutionJob $job, Run $run, RunAgent $slot, AgentResultContext $context, string $source): ExecutionHome
    {
        // Whether the workspace is writable follows the turn role alone, the
        // same single fact AgentExecutionProcessor::home() derives independently
        // on the agent-role side. A second, caller-supplied flag here could
        // drift from that derivation and hand the two sides a home whose
        // workspace they place differently.
        $writable = $context->role === AgentRole::IMPLEMENTATION;
        // Availability is checked before any request can be claimed. The one
        // container binding is also used by the database-free consumer.
        try {
            app()->makeWith(AgentAdapter::class, ['providerAlias' => $slot->provider_profile]);
        } catch (AgentExecutionException $exception) {
            $this->runs->recordStepEvent($run->id, $job->step_type, ExecutionJobState::FAILED, $exception->reason);
            throw $exception;
        }
        $intent = $this->intent($job);
        $key = $this->executionKey($job, $slot, $context);
        $executionId = $intent[$key] ?? hash('sha256', $job->idempotency_key.':'.$key);
        if (! is_string($executionId) || preg_match('/\A[0-9a-f]{64}\z/D', $executionId) !== 1) {
            throw new AgentExecutionException('agent_execution_identity_invalid');
        }
        if (! isset($intent[$key])) {
            $this->persist($job, [...$intent, $key => $executionId]);
        }
        $directoryId = substr($executionId, 0, 32);
        AgentExecutionProcessor::initializeLifecycleLock();
        $input = AgentExecutionProcessor::inputRoot().'/execution-'.$directoryId;
        $output = AgentExecutionProcessor::outputRoot().'/execution-'.$directoryId;
        $bindingPath = $input.'/binding.json';
        if (is_file($bindingPath)) {
            $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes($bindingPath));
            if ($request->string('context_hash') !== hash('sha256', $context->toJson())
                || $request->string('run_id') !== $run->id || $request->string('slot_id') !== $slot->slot_id
                || $request->string('session_id') !== $slot->session_id) {
                throw new AgentExecutionException('agent_staging_binding_invalid');
            }

            return $this->workerHome($request);
        }
        if (file_exists($input) || is_link($input) || file_exists($output) || is_link($output)) {
            throw new AgentExecutionException('agent_staging_incomplete');
        }
        if (isset($intent['agent_dispatched_'.$executionId])) {
            $consumed = RunArtifact::query()->where('run_id', $run->id)
                ->where('kind', RunArtifactKind::PROVIDER_RAW->value)
                ->where('execution_id', $executionId)->first();
            if ($consumed instanceof RunArtifact) {
                // This execution's answer already became a durable, consumed
                // step effect (an imported patch, a stored provider artifact)
                // before its home was torn down. A redelivery under the exact
                // same identity must never resurrect that outcome: either the
                // artifact is still intact (block it by name) or retention
                // has since removed it (name that boundary instead).
                throw new AgentExecutionException($consumed->isDeleted() ? 'artifact_retention_expired' : 'agent_execution_already_terminal');
            }
            if (isset($intent['agent_imported_'.$executionId])) {
                // An implementation or fix turn's importChanges() already
                // mutated the run's worktree for this identity, but a
                // RunTransitionConflict from bindActualChangedPaths() (a
                // concurrent run-version change) or a crash struck before
                // persistOutcome() could write the PROVIDER_RAW artifact —
                // see RunImplementation::markImported() at the call site
                // right after importChanges() succeeds. The artifact check
                // above alone cannot see that outcome, yet recreating this
                // identity would re-run the whole turn and risk importing a
                // second, possibly divergent patch on top of the one already
                // applied. This marker is the import's own durable effect and
                // is consumed the same as a stored artifact.
                throw new AgentExecutionException('agent_execution_already_terminal');
            }
            // Neither an artifact nor an import was ever bound to this
            // identity: its turn, if it ran at all, never became a durable
            // step effect (e.g. it parked pending a human decision before any
            // publication). Recreating its home under the same execution
            // identity is safe — every downstream store stays idempotent by
            // content digest, and a still-outstanding request remains
            // protected by the binding.json branch above and assertAlive()
            // against a second provider start.
            //
            // This branch is reached only once the home's own binding.json is
            // already gone — i.e. after destroy() has run in the calling
            // turn's own finally block. A hard process kill between an
            // implementation or fix turn's importChanges() and destroy()
            // happens before that finally block's destroy() call ever runs,
            // so binding.json is still on disk and the is_file($bindingPath)
            // branch above serves that redelivery instead — this check is
            // never consulted for a true crash in that window. The worktree
            // stays safe there for a different, already-proven reason:
            // RunPatchImporter::partition() treats a path already identical
            // to its source as nothing to import, so a redelivered
            // handleResult() reports fewer actual paths than the unchanged
            // AgentResult::changedPaths list and fails closed on
            // reported_path_mismatch instead of reapplying or corrupting the
            // change (see ImplementationImportIsolationTest::
            // test_a_replayed_import_of_an_already_applied_change_is_silently_skipped).
        }
        if (! mkdir($input, 0750) || ! chmod($input, 0750) || ! mkdir($output, 01730) || ! chmod($output, 01730)) {
            throw new AgentExecutionException('agent_staging_unavailable');
        }
        $home = null;
        try {
            $home = $this->homes->create($input, $output, $slot->slot_id, $slot->session_id, $source,
                $this->instructions->get($slot->provider_profile), $context->instructionSnapshot, $context->runtimeProfile,
                new CredentialProjection($slot->provider_profile, $this->revisions->revision($slot->provider_profile), []),
                writableWorkspace: $writable, turnContext: $context);
            $request = new AgentExecutionRequest([
                'schema' => 'ai6.agent-execution.v1', 'execution_id' => $executionId, 'run_id' => $run->id,
                'slot_id' => $slot->slot_id, 'session_id' => (string) $slot->session_id,
                'role' => $context->role->value, 'attempt' => $context->attempt,
                'home' => 'execution-'.$directoryId.'/'.basename($home->root), 'context_hash' => hash('sha256', $context->toJson()),
                'prompt_hash' => $context->promptSnapshot->hash, 'instruction_hash' => $context->instructionSnapshot->hash,
                'runtime_profile_id' => $context->runtimeProfile->id, 'runtime_profile_hash' => $context->runtimeProfile->hash,
                'provider_alias' => $slot->provider_profile, 'credential_revision' => $this->revisions->revision($slot->provider_profile),
                'deadline_at' => time() + $this->policies->get(ProcessPolicyName::AGENT)->timeoutSeconds + 30,
            ]);
            AgentExecutionProcessor::writeDocument($input.'/projection.json', $home->workspaceProjection);
            AgentExecutionProcessor::writeDocument($bindingPath, $request->fields);

            return $home;
        } catch (Throwable $exception) {
            if ($home !== null) {
                $this->homes->destroy($home);
            }
            $this->remove($input);
            $this->remove($output);
            throw $exception;
        }
    }

    /** @param list<string> $unreachablePaths */
    public function dispatchOrCollect(Run $run, ExecutionJob $job, ExecutionHome $home, AgentResultContext $context, array $unreachablePaths = []): ?AgentTurnResult
    {
        $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes(dirname($home->root).'/binding.json'));
        try {
            $this->assertLease($job);
            $this->assertCurrent($run, $request);
            if ($this->mayExecuteHere()) {
                if (isset($this->intent($job)['agent_dispatched_'.$request->string('execution_id')])) {
                    throw new AgentExecutionException('agent_execution_already_started');
                }
                $this->persist($job, [...$this->intent($job), 'agent_dispatched_'.$request->string('execution_id') => true]);
                $adapter = app()->makeWith(AgentAdapter::class, ['providerAlias' => $request->string('provider_alias')]);
                $answer = $adapter->turn($context, $home, function () use ($run, $job, $request): void {
                    $this->assertLease($job);
                    $this->assertCurrent($run, $request);
                }, $unreachablePaths);
                $this->assertCurrent($run, $request);
                try {
                    $this->redactor->assertValidInput($answer->bytes);
                } catch (InvalidRedactionInputException) {
                    $invalid = new AgentTurnResult('', $answer->usage, $answer->usageSource);
                    $this->remember($run, $request, $invalid);
                    $this->persistAnswer($run, $job, $request, $invalid, 'invalid_json', null);
                    throw new InvalidAgentResponse('agent_response_invalid_utf8');
                }
                $this->remember($run, $request, $answer);
                $this->persistAnswer($run, $job, $request, $answer, 'ok', null);

                return $answer;
            }
            $mailbox = $this->mailboxes->forRole(ExecutionRole::AGENT);
            $receiptPath = dirname($home->root).'/receipt.json';
            try {
                if (is_file($receiptPath)) {
                    $resultBytes = AgentExecutionProcessor::readBytes($receiptPath);
                } else {
                    $message = $mailbox->read(MailboxMessageType::RESULT, $request->string('slot_id'), $request->deliveryId(),
                        new RedactionContext((string) $run->project_id, $run->id, 'agent-result'));
                    $resultBytes = $message->content;
                    // The receipt lives in worker-owned input, so a resumed
                    // collector never needs to consume an envelope twice.
                    AgentExecutionProcessor::writeDocument($receiptPath, $this->json->decode($resultBytes, new RedactionContext('worker', $run->id, 'agent-receipt')));
                }
            } catch (MailboxRejectedException $exception) {
                if ($exception->reason !== MailboxRejection::INCOMPLETE) {
                    throw new AgentExecutionException('agent_result_'.$exception->reason->value);
                }
                try {
                    if (! isset($this->intent($job)['agent_dispatched_'.$request->string('execution_id')])) {
                        $this->persist($job, [...$this->intent($job), 'agent_dispatched_'.$request->string('execution_id') => true]);
                    }
                    $mailbox->write(MailboxMessageType::REQUEST, $request->string('slot_id'), $request->deliveryId(), $request->toJson());
                } catch (MailboxRejectedException $writeFailure) {
                    if ($writeFailure->reason !== MailboxRejection::REPLAY) {
                        throw $writeFailure;
                    }
                }
                $this->assertAlive($request);

                return null;
            }
            $document = AgentExecutionResultDocument::fromJson($resultBytes);
            if ($document->request->fields != $request->fields || $document->completedAt > time()) {
                throw new AgentExecutionException('agent_result_binding_invalid');
            }
            $boot = $this->runtimeDocument(AgentExecutionProcessor::outputRoot().'/attestations/agent.json');
            $claim = $this->runtimeDocument(AgentExecutionProcessor::outputRoot().'/claims/'.$request->string('execution_id').'.json');
            if (($boot['agent_boot_id'] ?? null) !== $document->bootId || ($claim['agent_boot_id'] ?? null) !== $document->bootId
                || ($claim['execution_id'] ?? null) !== $request->string('execution_id')) {
                throw new AgentExecutionException('agent_result_boot_invalid');
            }
            $this->assertAlive($request, $document->completedAt);
            $bytes = AgentExecutionProcessor::readBytes($home->resultDirectory.'/answer.txt', $this->policies->get(ProcessPolicyName::AGENT)->outputLimitBytes);
            $this->redactor->assertValidInput($bytes);
            if (! hash_equals($document->answerHash, hash('sha256', $bytes))) {
                throw new AgentExecutionException('agent_result_answer_hash_invalid');
            }
            $this->assertCurrent($run, $request);
            $this->assertLease($job);
            $answer = new AgentTurnResult($bytes, $document->answer->usage, $document->answer->usageSource);
            $this->remember($run, $request, $answer);
            $this->persistAnswer($run, $job, $request, $answer, $document->state, $document->bootId);
            if ($document->state === 'provider_error') {
                throw new AgentExecutionException($document->reason);
            }
            if ($document->state === 'invalid_json') {
                throw new InvalidAgentResponse($document->reason);
            }

            return $answer;
        } catch (Throwable $exception) {
            $reason = $exception instanceof AgentExecutionException ? $exception->reason
                : ($exception instanceof InvalidAgentResponse ? $exception->reason : 'agent_execution_failed');
            $bootId = 'unknown';
            try {
                $claim = $this->runtimeDocument(AgentExecutionProcessor::outputRoot().'/claims/'.$request->string('execution_id').'.json');
                if (is_string($claim['agent_boot_id'] ?? null) && preg_match('/\A[0-9a-f]{32}\z/D', $claim['agent_boot_id']) === 1) {
                    $bootId = $claim['agent_boot_id'];
                }
            } catch (Throwable) {
                // An unclaimed or malformed execution has no trusted boot.
            }
            $this->runs->recordStepEvent($run->id, $job->step_type, ExecutionJobState::FAILED,
                $reason.': '.$request->string('execution_id').' boot: '.$bootId, 'agent-boundary:'.$request->string('execution_id'));
            throw $exception;
        }
    }

    public function destroy(ExecutionHome $home): void
    {
        AgentExecutionProcessor::withLifecycleLock(fn () => $this->destroyLocked($home));
    }

    private function destroyLocked(ExecutionHome $home): void
    {
        $input = dirname($home->root);
        $output = dirname($home->outputRoot);
        if (preg_match('/\Aexecution-[0-9a-f]{32}\z/D', basename($input)) !== 1
            || dirname($input) !== AgentExecutionProcessor::inputRoot() || dirname($output) !== AgentExecutionProcessor::outputRoot()) {
            throw new AgentExecutionException('agent_cleanup_binding_invalid');
        }
        $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes($input.'/binding.json'));
        $this->homes->destroy($home);
        $this->mailboxes->forRole(ExecutionRole::AGENT)->cleanupDelivery($request->deliveryId());
        foreach (['claims', 'heartbeats'] as $kind) {
            $path = AgentExecutionProcessor::outputRoot().'/'.$kind.'/'.$request->string('execution_id').'.json';
            if ((file_exists($path) || is_link($path)) && ! self::tryFilesystemOperation(static fn (): bool => unlink($path))) {
                throw new AgentExecutionException('agent_cleanup_failed');
            }
        }
        $this->remove($input);
        $this->remove($output);
    }

    public function cleanupStoppedJob(ExecutionJob $job): void
    {
        $current = $job->fresh();
        $run = $current?->run()->first();
        if ($current === null || ($run instanceof Run && in_array($run->state, [RunState::QUEUED, RunState::RUNNING], true)
            && $run->pending_status_operation_id === null
            && ! in_array($current->state, [ExecutionJobState::SUCCEEDED, ExecutionJobState::FAILED], true))) {
            return;
        }
        foreach ($this->intent($current) as $key => $value) {
            if (preg_match('/\Aagent_execution_[0-9a-f]{64}\z/D', $key) !== 1) {
                continue;
            }
            if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                throw new AgentExecutionException('agent_cleanup_binding_invalid');
            }
            $input = AgentExecutionProcessor::inputRoot().'/execution-'.substr($value, 0, 32);
            $output = AgentExecutionProcessor::outputRoot().'/execution-'.substr($value, 0, 32);
            if (is_file($input.'/binding.json')) {
                $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes($input.'/binding.json'));
                if ($request->string('execution_id') !== $value || $request->string('run_id') !== $current->run_id) {
                    throw new AgentExecutionException('agent_cleanup_binding_invalid');
                }
                $this->destroy($this->workerHome($request));
            } else {
                $this->remove($input);
                $this->remove($output);
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    public function store(Run $run, RunArtifactKind $kind, string $bytes, array $metadata, RedactionContext $context): RunArtifact
    {
        $key = $run->id.':'.hash('sha256', $bytes);
        $cached = $this->answerArtifacts[$key] ?? null;
        if ($kind === RunArtifactKind::PROVIDER_RAW && $cached instanceof RunArtifact) {
            $fresh = $cached->fresh();
            if ($fresh instanceof RunArtifact && ! $fresh->isDeleted()) {
                return $fresh;
            }
            // Retention removed the cached artifact since it was remembered;
            // fall through and let the real store call name that terminally.
            unset($this->answerArtifacts[$key]);
        }
        $bound = $this->answerMetadata[$key] ?? ['usage' => [], 'usage_source' => 'unknown'];
        $executionId = $bound['execution_id'] ?? null;

        // Not cached here: distinct executions can legitimately share these
        // same bytes (e.g. the same malformed answer repeated across retry
        // attempts), each with its own execution id. Only persistAnswer()
        // remembers a hit, scoped to the one call already bound to a single
        // execution within dispatchOrCollect()'s own synchronous flow.
        return $this->artifacts->store($run, $kind, $bytes,
            [...$metadata, ...$bound], $context, is_string($executionId) ? $executionId : null);
    }

    public function revision(ExecutionJob $job): string
    {
        return 'i'.Intervention::query()->where('bound_step_key', $job->idempotency_key)->count()
            .'h'.HumanRequest::query()->where('bound_step_key', $job->idempotency_key)->whereNotNull('resolved_at')->count();
    }

    /**
     * The one place the execution identity's own intent key is derived, so
     * prepare() and markImported() can never drift onto two different
     * formulas for the same turn.
     */
    private function executionKey(ExecutionJob $job, RunAgent $slot, AgentResultContext $context): string
    {
        return 'agent_execution_'.hash('sha256', $slot->slot_id.':'.$slot->session_id.':'.$context->attempt.':'.$context->promptSnapshot->hash.':'.$this->revision($job));
    }

    /**
     * Records that this identity's importChanges() already mutated the run's
     * worktree, independently of whether a PROVIDER_RAW artifact for it ever
     * gets written. Call this immediately after a successful import and
     * before any later step that could still fail (e.g. bindActualChangedPaths()'s
     * optimistic-concurrency check) — that ordering is what lets prepare()
     * recognize a redelivery of this identity as already consumed even when
     * persistOutcome() never ran.
     *
     * The written key's own shape ('agent_imported_<hex>') must stay listed
     * in intentMatches()'s stripped-key pattern below; otherwise a step
     * redelivered after this call fails intent comparison as
     * invalid_step_intent before it ever reaches prepare()'s consumed check.
     */
    public function markImported(ExecutionJob $job, RunAgent $slot, AgentResultContext $context): void
    {
        $intent = $this->intent($job);
        $executionId = $intent[$this->executionKey($job, $slot, $context)] ?? null;
        if (! is_string($executionId)) {
            // prepare() always binds this key before any provider turn
            // starts; a missing key here means this turn was never actually
            // dispatched through this runner.
            throw new AgentExecutionException('agent_execution_identity_invalid');
        }
        if (! isset($intent['agent_imported_'.$executionId])) {
            $this->persist($job, [...$intent, 'agent_imported_'.$executionId => true]);
        }
    }

    /**
     * Whether the current step's execution has already been handed to the
     * mailbox or the reduced direct path. Once dispatched, the writable
     * workspace belongs to the concurrently running turn, so a caller must
     * not walk it again until the turn's own result is collected.
     */
    public function dispatched(ExecutionJob $job, ExecutionHome $home): bool
    {
        $request = AgentExecutionRequest::fromJson(AgentExecutionProcessor::readBytes(dirname($home->root).'/binding.json'));

        // Bound to this home's own execution identity: a stale
        // agent_dispatched_<hex> key from an earlier, already-destroyed
        // attempt must not skip the pre-turn guard for a fresh home that was
        // never itself handed to the mailbox.
        return isset($this->intent($job)['agent_dispatched_'.$request->string('execution_id')]);
    }

    /** @return array<string, scalar> */
    public function intent(ExecutionJob $job): array
    {
        $stored = $job->fresh()?->intent;
        if (! is_string($stored)) {
            return [];
        }
        $values = $this->json->decode($stored, new RedactionContext('worker', $job->run_id, 'step-intent'));
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new AgentExecutionException('agent_step_intent_invalid');
            }
        }

        return $values;
    }

    /** @param array<string, scalar> $intent */
    public function persist(ExecutionJob $job, array $intent): void
    {
        if (! $this->runs->persistIntent($job, (string) $job->lease_owner, $intent)) {
            throw new AgentExecutionException('agent_step_lease_lost');
        }
    }

    /**
     * @param  array<string, scalar>  $expected
     *
     * The pattern below is the closed list of intent keys this runner itself
     * writes for a step (prepare()'s execution/dispatched keys, this class's
     * own markImported(), and the review/security-slot and turn-revision
     * keys other consumers bind alongside it) — every one of them must stay
     * listed here, or a redelivery that carries it fails as
     * invalid_step_intent before the step's own consumed-execution guards
     * ever run.
     */
    public function intentMatches(string $stored, array $expected): bool
    {
        try {
            $decoded = $this->json->decode($stored, new RedactionContext('worker', null, 'step-intent'));
            foreach (array_keys($decoded) as $key) {
                if (is_string($key) && preg_match('/\Aagent_(?:execution_[0-9a-f]{64}|dispatched_[0-9a-f]{64}|imported_[0-9a-f]{64}|verifier_slot_[0-9a-f]{64}|security_slot_i[0-9]+h[0-9]+|review_session_[a-zA-Z0-9_-]+|turn_revision|provider_attempt)\z/D', $key) === 1
                    && is_scalar($decoded[$key])) {
                    unset($decoded[$key]);
                }
            }

            return $decoded === $expected;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertCurrent(Run $run, AgentExecutionRequest $request): void
    {
        $current = $run->fresh();
        if (! $current instanceof Run || ! in_array($current->state, [RunState::QUEUED, RunState::RUNNING], true)
            || $current->pending_status_operation_id !== null) {
            throw new AgentExecutionException('agent_result_after_cancel');
        }
        if ($request->integer('deadline_at') <= time()) {
            throw new AgentExecutionException('agent_execution_deadline_exceeded');
        }
        if ($this->revisions->revision($request->string('provider_alias')) !== $request->string('credential_revision')) {
            throw new AgentExecutionException('agent_credential_revision_changed');
        }
        $profile = app(ProviderRuntimeProfileRegistry::class)->get($request->string('runtime_profile_id'));
        if ($profile->hash !== $request->string('runtime_profile_hash')) {
            throw new AgentExecutionException('agent_runtime_profile_changed');
        }
        if (! RunAgent::query()->where('run_id', $run->id)->where('slot_id', $request->string('slot_id'))
            ->where('session_id', $request->string('session_id'))->where('is_active', true)->exists()) {
            throw new AgentExecutionException('agent_session_binding_changed');
        }
    }

    private function assertLease(ExecutionJob $job): void
    {
        $current = $job->fresh();
        if ($current?->state !== ExecutionJobState::RUNNING || $current->lease_owner !== $job->lease_owner
            || $current->lease_expires_at === null || $current->lease_expires_at->isPast()) {
            throw new AgentExecutionException('agent_step_lease_lost');
        }
    }

    private function assertAlive(AgentExecutionRequest $request, ?int $completedAt = null): void
    {
        if ($request->integer('deadline_at') <= time()) {
            throw new AgentExecutionException('agent_execution_deadline_exceeded');
        }
        $root = AgentExecutionProcessor::outputRoot();
        $path = $root.'/claims/'.$request->string('execution_id').'.json';
        if (! file_exists($path)) {
            // No separate acceptance boundary before the full deadline, matching
            // CheckRunner::assertExecutionAlive(): the reconciler redelivers a
            // parked step only on its own tick, well beyond a fixed timeout-sized
            // reserve, so an early boundary here would fail a request the agent
            // simply has not claimed yet.
            return;
        }
        $claim = $this->runtimeDocument($path);
        $boot = $this->runtimeDocument($root.'/attestations/agent.json');
        $maximumAge = $boot['heartbeat_max_age'] ?? null;
        $interval = $boot['heartbeat_interval'] ?? null;
        if (count($boot) !== 5 || ($boot['schema'] ?? null) !== 'ai6.agent-boot.v1'
            || ! is_int($maximumAge) || $maximumAge < 1 || $maximumAge > 3600
            || ! is_int($interval) || $interval < 1 || $interval > $maximumAge
            || ! is_string($boot['agent_boot_id'] ?? null) || preg_match('/\A[0-9a-f]{32}\z/D', $boot['agent_boot_id']) !== 1
            || ! is_int($boot['recorded_at'] ?? null) || $boot['recorded_at'] > time() || time() - $boot['recorded_at'] > $maximumAge
            || count($claim) !== 4 || ($claim['schema'] ?? null) !== 'ai6.agent-claim.v1'
            || ! is_int($claim['recorded_at'] ?? null) || $claim['recorded_at'] > time()
            || ($claim['execution_id'] ?? null) !== $request->string('execution_id')
            || ($claim['agent_boot_id'] ?? null) !== $boot['agent_boot_id']) {
            throw new AgentExecutionException('agent_execution_boot_changed');
        }
        $pulsePath = $root.'/heartbeats/'.$request->string('execution_id').'.json';
        $pulse = file_exists($pulsePath) ? $this->runtimeDocument($pulsePath) : $claim;
        if (count($pulse) !== 4 || ! in_array($pulse['schema'] ?? null, ['ai6.agent-heartbeat.v1', 'ai6.agent-claim.v1'], true)
            || ($pulse['execution_id'] ?? null) !== $request->string('execution_id')
            || ($pulse['agent_boot_id'] ?? null) !== $claim['agent_boot_id']
            || ! is_int($pulse['recorded_at'] ?? null) || $pulse['recorded_at'] > time() || $pulse['recorded_at'] < $claim['recorded_at']
            || ($completedAt === null && time() - $pulse['recorded_at'] > $maximumAge)
            || ($completedAt !== null && ($completedAt < $claim['recorded_at'] || abs($completedAt - $pulse['recorded_at']) > 1))) {
            throw new AgentExecutionException('agent_execution_heartbeat_stale');
        }
    }

    /** @return array<string, mixed> */
    private function runtimeDocument(string $path): array
    {
        return $this->json->decode(AgentExecutionProcessor::readBytes($path, 4096), new RedactionContext('worker', null, 'agent-runtime'));
    }

    private function workerHome(AgentExecutionRequest $request): ExecutionHome
    {
        $home = AgentExecutionProcessor::home($request);
        $projection = $this->json->decode(AgentExecutionProcessor::readBytes(dirname($home->root).'/projection.json'), new RedactionContext('worker', null, 'workspace-projection'));
        foreach ($projection as $path => $hash) {
            if (! is_string($path) || ($hash !== null && ! is_string($hash))) {
                throw new AgentExecutionException('agent_projection_invalid');
            }
        }

        return new ExecutionHome($home->root, $home->outputRoot, $home->workspace, $home->home, $home->instructionOverlay,
            $home->runtimeConfiguration, $home->authDirectory, $home->resultDirectory, $home->artifactDirectory, $home->patchDirectory, $projection);
    }

    private function remember(Run $run, AgentExecutionRequest $request, AgentTurnResult $answer): void
    {
        $this->answerMetadata[$run->id.':'.hash('sha256', $answer->bytes)] = [
            'execution_id' => $request->string('execution_id'), 'session_id' => $request->string('session_id'),
            'role' => $request->string('role'), 'slot_id' => $request->string('slot_id'), 'attempt' => $request->integer('attempt'),
            ...$answer->metadata(),
        ];
        // The entry is only ever read again later within the same turn's
        // synchronous flow; bound the map so the singleton binding cannot
        // accumulate one entry per turn for the whole worker process lifetime.
        while (count($this->answerMetadata) > self::MAX_REMEMBERED_ANSWERS) {
            array_shift($this->answerMetadata);
        }
    }

    private function persistAnswer(Run $run, ExecutionJob $job, AgentExecutionRequest $request, AgentTurnResult $answer, string $state, ?string $boot): void
    {
        $key = $run->id.':'.hash('sha256', $answer->bytes);
        $this->answerMetadata[$key] = [
            ...($this->answerMetadata[$key] ?? []),
            'agent_boot_id' => $boot, 'state' => $state,
        ];
        // Implementation import remains atomic with its limit check. Its
        // consumer stores the bound answer only after validating that batch.
        if ($request->string('role') === AgentRole::IMPLEMENTATION->value && $state === 'ok') {
            return;
        }
        $limit = $this->limits->evaluate($run, [], [['bytes' => strlen($answer->bytes)]], strlen($answer->bytes));
        if ($limit instanceof ImportLimitResult) {
            throw new AgentExecutionLimitReached($limit);
        }
        $artifact = $this->artifacts->store(
            $run, RunArtifactKind::PROVIDER_RAW, $answer->bytes, [
                'kind' => RunArtifactKind::PROVIDER_RAW->value, 'role' => $request->string('role'),
                'slot_id' => $request->string('slot_id'), 'session_id' => $request->string('session_id'),
                'attempt' => $request->integer('attempt'), 'round_number' => $job->step_number,
                'execution_id' => $request->string('execution_id'), 'agent_boot_id' => $boot,
                'state' => $state, ...$answer->metadata(),
            ], new RedactionContext((string) $run->project_id, $run->id, 'provider-answer'), $request->string('execution_id'),
        );
        $this->rememberArtifact($key, $artifact);
    }

    private function rememberArtifact(string $key, RunArtifact $artifact): void
    {
        $this->answerArtifacts[$key] = $artifact;
        while (count($this->answerArtifacts) > self::MAX_REMEMBERED_ANSWERS) {
            array_shift($this->answerArtifacts);
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path)) {
            if (! self::tryFilesystemOperation(static fn (): bool => unlink($path))) {
                throw new AgentExecutionException('agent_cleanup_failed');
            }

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $remove = $entry->isDir() && ! $entry->isLink()
                ? static fn (): bool => rmdir($entry->getPathname())
                : static fn (): bool => unlink($entry->getPathname());
            if (! self::tryFilesystemOperation($remove)) {
                throw new AgentExecutionException('agent_cleanup_failed');
            }
        }
        if (! self::tryFilesystemOperation(static fn (): bool => rmdir($path))) {
            throw new AgentExecutionException('agent_cleanup_failed');
        }
    }

    /**
     * A failed unlink()/rmdir() raises E_WARNING before its own return value
     * is examined; under Laravel's error handler that becomes an
     * \ErrorException here, bypassing the named AgentExecutionException the
     * surrounding `if (! ...)` check exists to raise. Catching it and
     * reporting failure the same way a false return would keeps the named
     * reason reachable regardless of which of the two paths the runtime
     * takes (matches ExecutionHomeManager::tryFilesystemOperation()).
     *
     * @param  callable(): bool  $operation
     */
    private static function tryFilesystemOperation(callable $operation): bool
    {
        try {
            return $operation();
        } catch (Throwable) {
            return false;
        }
    }
}
