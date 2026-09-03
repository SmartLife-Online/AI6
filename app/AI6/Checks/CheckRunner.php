<?php

namespace App\AI6\Checks;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RetentionCategory;
use App\AI6\Runs\RetentionPolicy;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ExecutionMailbox;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\MailboxRejectedException;
use App\AI6\Shared\Process\MailboxRejection;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Execute exactly one server-defined check profile against one run tree.
 *
 * Everything this class needs already exists: the checker mailbox, the checker
 * process policy with its argument list and environment allowlist, the isolated
 * tree export and the central redactor. It adds no second process path, no
 * second mailbox and no redaction rules of its own.
 */
final readonly class CheckRunner
{
    public function __construct(
        private CheckProfileRegistry $profiles,
        private ControlProcessRunner $processes,
        private ExecutionMailboxFactory $mailboxes,
        private IsolatedTreeExporter $exporter,
        private CheckTreeBinding $treeBinding,
        private Redactor $redactor,
        private CheckProcessResultInterpreter $interpreter,
        private ProcessPolicyRegistry $policies,
        private SecurityPolicy $security,
        private CheckerRuntimeConfiguration $runtime,
        private RetentionPolicy $retention,
    ) {}

    /**
     * Whether this runtime may execute a check at all.
     *
     * Only the checker role carries the isolation the check promises: no
     * network, no credentials, no managed clone, and the full
     * ProcessIsolationVerifier. While `REQUIRE_CHECKER_NETWORK_ISOLATION` is
     * active, executing anywhere else is refused rather than silently run under
     * the worker's network and filesystem — a check profile executes the
     * managed project's own untrusted code.
     */
    public function mayExecuteHere(): bool
    {
        return config('ai6.runtime_role') === ExecutionRole::CHECKER->value
            || ! $this->security->isEnabled(SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION);
    }

    /**
     * Stage or collect one strict role-correct execution.
     *
     * A null return means that the immutable request is published but no bound
     * result exists yet. Repeated worker polls reuse the same execution id,
     * staging directory and delivery id and therefore cannot start a second
     * checker process.
     */
    public function dispatchOrCollect(
        Run $run,
        CheckPhase $phase,
        string $profileName,
        string $executionId,
        int $deadlineAt,
    ): ?CheckResultRecord {
        $profile = $this->profiles->get($profileName);
        if (! $profile->allowsPhase($phase) || $profile->requiresNetwork) {
            throw new CheckProfileConfigurationException('check_profile_phase_not_allowed', 'The check profile is not allowed in this checker phase.');
        }
        $root = config('ai6.execution_mailboxes.checker_root');
        if (! is_string($root) || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('The checker execution root is unavailable.');
        }
        $stagedRoot = rtrim(str_replace('\\', '/', (string) realpath($root)), '/').'/staged';
        if (! is_dir($stagedRoot) && ! mkdir($stagedRoot, 0750, true) && ! is_dir($stagedRoot)) {
            throw new RuntimeException('The checker staging root could not be created.');
        }
        $batch = $stagedRoot.'/'.$executionId;
        if (! is_dir($batch)) {
            if (! mkdir($batch, 0750)) {
                throw new RuntimeException('The checker staging directory could not be created.');
            }
            try {
                $worktree = $run->worktree_path;
                if (! is_string($worktree) || ! is_dir($worktree) || is_link($worktree)) {
                    throw new RuntimeException('The bound run worktree is unavailable.');
                }
                $this->exporter->export($worktree, $batch.'/tree', false);
                if (! mkdir($batch.'/baseline', 0750)) {
                    throw new RuntimeException('The check baseline directory could not be created.');
                }
            } catch (Throwable $exception) {
                $this->remove($batch);
                throw $exception;
            }
        }

        $before = $this->treeBinding->hash($batch.'/tree');
        $baseline = $this->treeBinding->hash($batch.'/baseline');
        $key = CheckResult::key($run->id, $run->evidence_epoch, $phase, $profileName, $before);
        if (($record = $this->liveResult($key)) instanceof CheckResultRecord) {
            return $record;
        }
        $request = new CheckExecutionRequest($executionId, $run->id, $phase, $profileName, $before, $baseline, $deadlineAt);
        $mailbox = $this->mailboxes->forRole(ExecutionRole::CHECKER);
        $deliveryId = CheckerExecutionProcessor::deliveryId($executionId);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'check:'.$phase->value);

        try {
            $message = $mailbox->read(MailboxMessageType::RESULT, 'check-'.$run->id, $deliveryId, $context);
        } catch (MailboxRejectedException $exception) {
            if ($exception->reason !== MailboxRejection::INCOMPLETE) {
                throw $exception;
            }
            try {
                $mailbox->write(MailboxMessageType::REQUEST, 'check-'.$run->id, $deliveryId, $request->toJson());
            } catch (MailboxRejectedException $writeFailure) {
                if ($writeFailure->reason !== MailboxRejection::REPLAY) {
                    throw $writeFailure;
                }
            }

            try {
                $this->assertExecutionAlive($executionId, $deadlineAt);
            } catch (CheckExecutionBoundaryReached $boundary) {
                $this->cleanupAsyncExecution($mailbox, $deliveryId, $batch, $executionId);
                throw $boundary;
            }

            return null;
        }

        try {
            $document = CheckExecutionResultDocument::fromJson($message->content);
            if ($document->executionId !== $executionId || $document->runId !== $run->id
                || $document->phase !== $phase || $document->profile !== $profileName
                || $document->deadlineAt !== $deadlineAt
                || ! hash_equals($before, $document->sourceTreeSha256)
                || ! hash_equals($baseline, $document->baselineSha256)
                || (! $profile->mutatesTree && ! hash_equals($before, $document->resultTreeSha256))
                || ($document->state === CheckResultState::SUCCEEDED
                    && ! hash_equals($baseline, $document->observedBaselineSha256))
                || $document->metadata !== $profile->declaredMetadata()) {
                throw new CheckExecutionContractException('check_result_binding_invalid');
            }
            if (time() >= $deadlineAt) {
                throw new CheckExecutionContractException('check_result_after_terminal_boundary');
            }
            $this->assertResultBootBinding($executionId, $document->checkerBootId);
        } catch (CheckExecutionContractException $exception) {
            $this->cleanupAsyncExecution($mailbox, $deliveryId, $batch, $executionId);
            throw $exception;
        }
        $result = new CheckResult(
            $profileName, $phase, $document->state, $document->exitCode, $document->reason,
            $document->durationMilliseconds, $document->redactedOutput, $before,
            $document->resultTreeSha256, $document->metadata,
        );
        $this->cleanupAsyncExecution($mailbox, $deliveryId, $batch, $executionId);
        $record = $this->persist($run, $result, $key);
        // These transient attributes are consumed immediately by RunCheckStep
        // for the durable timeline event. The published check_results schema
        // remains the immutable AI6-021 contract.
        $record->setAttribute('checker_execution_id', $document->executionId);
        $record->setAttribute('checker_boot_id', $document->checkerBootId);
        $record->setAttribute('checker_deadline_at', $document->deadlineAt);

        return $record;
    }

    private function assertExecutionAlive(string $executionId, int $deadlineAt): void
    {
        if (time() >= $deadlineAt) {
            throw new CheckExecutionBoundaryReached('check_execution_deadline_exceeded');
        }
        $output = config('ai6.execution_mailboxes.checker_output_root');
        if (! is_string($output)) {
            return;
        }
        $heartbeatPath = rtrim($output, '/\\').'/heartbeats/'.$executionId.'.json';
        if (! is_file($heartbeatPath) || is_link($heartbeatPath)) {
            $claimPath = rtrim($output, '/\\').'/claims/'.$executionId.'.json';
            if (! is_file($claimPath) || is_link($claimPath)) {
                return;
            }
            $claim = json_decode((string) file_get_contents($claimPath), true);
            $attestationPath = rtrim($output, '/\\').'/attestations/checker.json';
            $attestation = is_file($attestationPath) && ! is_link($attestationPath)
                ? json_decode((string) file_get_contents($attestationPath), true) : null;
            if (! is_array($claim) || ! is_int($claim['recorded_at'] ?? null) || ! is_string($claim['checker_boot_id'] ?? null)) {
                throw new CheckExecutionBoundaryReached('check_execution_claim_invalid');
            }
            if ((is_array($attestation) && is_string($attestation['checker_boot_id'] ?? null)
                    && ! hash_equals($claim['checker_boot_id'], $attestation['checker_boot_id']))
                || time() - $claim['recorded_at'] > $this->runtime->heartbeatMaxAgeSeconds) {
                throw new CheckExecutionBoundaryReached('check_execution_checker_crashed');
            }

            return;
        }
        $heartbeat = json_decode((string) file_get_contents($heartbeatPath), true);
        if (! is_array($heartbeat) || ! is_int($heartbeat['recorded_at'] ?? null) || ! is_string($heartbeat['checker_boot_id'] ?? null)) {
            throw new CheckExecutionBoundaryReached('check_execution_heartbeat_invalid');
        }
        if (time() - $heartbeat['recorded_at'] > $this->runtime->heartbeatMaxAgeSeconds) {
            throw new CheckExecutionBoundaryReached('check_execution_heartbeat_stale');
        }
        $attestationPath = rtrim($output, '/\\').'/attestations/checker.json';
        if (is_file($attestationPath) && ! is_link($attestationPath)) {
            $attestation = json_decode((string) file_get_contents($attestationPath), true);
            if (is_array($attestation) && is_string($attestation['checker_boot_id'] ?? null)
                && ! hash_equals($heartbeat['checker_boot_id'], $attestation['checker_boot_id'])) {
                throw new CheckExecutionBoundaryReached('check_execution_checker_boot_changed');
            }
        }
    }

    private function assertResultBootBinding(string $executionId, string $bootId): void
    {
        $output = config('ai6.execution_mailboxes.checker_output_root');
        if (! is_string($output)) {
            throw new CheckExecutionContractException('check_result_boot_binding_missing');
        }
        $root = rtrim($output, '/\\');
        $heartbeat = $this->readBoundRuntimeDocument($root.'/heartbeats/'.$executionId.'.json');
        $attestation = $this->readBoundRuntimeDocument($root.'/attestations/checker.json');
        if (($heartbeat['execution_id'] ?? null) !== $executionId
            || ($heartbeat['checker_boot_id'] ?? null) !== $bootId
            || ($attestation['checker_boot_id'] ?? null) !== $bootId
            || ! is_int($attestation['recorded_at'] ?? null)
            || time() - $attestation['recorded_at'] > $this->runtime->attestationMaxAgeSeconds) {
            throw new CheckExecutionContractException('check_result_boot_binding_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function readBoundRuntimeDocument(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CheckExecutionContractException('check_result_boot_binding_missing');
        }
        $document = json_decode((string) file_get_contents($path), true);

        return is_array($document) ? $document : throw new CheckExecutionContractException('check_result_boot_binding_invalid');
    }

    /**
     * @param  int  $deliveryAttempt  the step delivery this execution belongs to. It
     *                                separates the mailbox orders of successive
     *                                deliveries; the result identity stays the four
     *                                coordinates of CheckResult::key().
     *
     * @throws CheckProfileConfigurationException when the profile is not server-defined,
     *                                            is not allowed in this phase, or declares a
     *                                            capability the runtime cannot grant
     * @throws CheckExecutionRoleRequired when this runtime is not the isolated checker role
     *                                    while the checker isolation control is active
     * @throws DuplicateCheckExecution when this exact execution already has a live result
     * @throws OrphanedCheckExecution when a prior delivery of this very attempt died
     *                                between its mailbox order and its result
     */
    public function run(Run $run, CheckPhase $phase, string $profileName, int $deliveryAttempt = 1): CheckResultRecord
    {
        if (! $this->mayExecuteHere()) {
            throw new CheckExecutionRoleRequired((string) config('ai6.runtime_role'));
        }

        $profile = $this->profiles->get($profileName);
        if (! $profile->allowsPhase($phase)) {
            throw new CheckProfileConfigurationException(
                'check_profile_phase_not_allowed',
                'The check profile is not declared for the requested phase.',
            );
        }
        if ($profile->requiresNetwork) {
            // Network stays off by default. A profile that declares a need is
            // never silently run without it: the checker runtime has no
            // interfaces at all, so the declaration fails closed (SEC-005).
            throw new CheckProfileConfigurationException(
                'check_profile_network_unavailable',
                'The check profile declares a network need the checker runtime does not grant.',
            );
        }

        $worktree = $run->worktree_path;
        if (! is_string($worktree) || ! is_dir($worktree) || is_link($worktree)) {
            throw new RuntimeException('The bound run worktree is unavailable.');
        }

        $batch = $this->createBatch();
        $tree = $batch.'/tree';
        $baseline = $batch.'/baseline';
        $outputs = $this->createOutputs();
        $context = new RedactionContext((string) $run->project_id, $run->id, 'check:'.$phase->value);
        $deliveryId = null;

        try {
            // Writable on purpose: a read-only tree could not be mutated at
            // all, which would make the mutation detection below unfalsifiable.
            $this->exporter->export($worktree, $tree, true);
            if (! mkdir($baseline, 0700)) {
                throw new RuntimeException('The check baseline directory could not be created.');
            }

            $before = $this->treeBinding->hash($tree);
            $key = CheckResult::key($run->id, $run->evidence_epoch, $phase, $profile->name, $before);
            $deliveryId = $this->claimDelivery($run, $phase, $profile, $key, $before, $deliveryAttempt, $context);

            $result = $this->execute($profile, $batch, $tree, $outputs, $context);
            $after = $this->treeBinding->hash($tree);

            return $this->persist($run, $this->outcome($profile, $phase, $result, $before, $after, $context), $key);
        } finally {
            $this->remove($baseline);
            $this->remove($tree);
            $this->remove($batch);
            $this->remove($outputs['result']);
            $this->remove($outputs['artifacts']);
            $this->remove($outputs['root']);
            if (is_string($deliveryId)) {
                $this->mailboxes->forRole(ExecutionRole::CHECKER)->cleanupDelivery($deliveryId);
            }
        }
    }

    /** Bind the complete current run tree through the same export used by every check. */
    public function currentTreeBinding(Run $run): string
    {
        $worktree = $run->worktree_path;
        if (! is_string($worktree) || ! is_dir($worktree) || is_link($worktree)) {
            throw new RuntimeException('The bound run worktree is unavailable.');
        }

        $batch = $this->createBatch();
        $tree = $batch.'/tree';
        try {
            $this->exporter->export($worktree, $tree, false);

            return $this->treeBinding->hash($tree);
        } finally {
            $this->remove($tree);
            $this->remove($batch);
        }
    }

    /**
     * Claim this execution and write its one order into the checker mailbox.
     *
     * The live result row is the idempotency guard and is checked before any
     * process can start: a redelivered step finds it and is rejected. The
     * mailbox delivery identifier additionally carries the delivery attempt, so
     * the order of an attempt that died mid-flight never blocks the next one —
     * a collision there means this very attempt was already ordered without ever
     * producing a result, which is a named, visible state rather than a silent
     * skip.
     */
    private function claimDelivery(
        Run $run,
        CheckPhase $phase,
        CheckProfile $profile,
        string $key,
        string $treeSha,
        int $deliveryAttempt,
        RedactionContext $context,
    ): string {
        if ($this->liveResult($key) instanceof CheckResultRecord) {
            throw new DuplicateCheckExecution($key);
        }

        $order = json_encode([
            'run_id' => $run->id,
            'phase' => $phase->value,
            'profile' => $profile->name,
            'tree_sha' => $treeSha,
            'delivery_attempt' => $deliveryAttempt,
            'command' => $profile->command(),
            'metadata' => $profile->declaredMetadata(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        try {
            $deliveryId = self::deliveryId($key, $deliveryAttempt);
            $this->mailboxes->forRole(ExecutionRole::CHECKER)->write(
                MailboxMessageType::REQUEST,
                'check-'.$run->id,
                $deliveryId,
                $this->redactor->redact($order, $context)->text,
            );
        } catch (MailboxRejectedException) {
            throw new OrphanedCheckExecution($key, $deliveryAttempt);
        }

        return $deliveryId;
    }

    /** The mailbox identity of one delivery of one execution. */
    public static function deliveryId(string $resultKey, int $deliveryAttempt): string
    {
        return hash('sha256', $resultKey."\0".$deliveryAttempt);
    }

    /**
     * The one live result of an execution key, if there is one.
     *
     * A key can carry several rows: a retry on the unchanged tree supersedes
     * its predecessor and keeps the same five coordinates. Only the row that is
     * not superseded is the current result, and it is never the oldest one, so
     * the distinction cannot be left to row order.
     */
    public function liveResult(string $resultKey): ?CheckResultRecord
    {
        return CheckResultRecord::query()
            ->where('result_key', $resultKey)
            ->get()
            ->first(static fn (CheckResultRecord $record): bool => $record->superseded_at === null);
    }

    /** @param array{root: string, result: string, artifacts: string} $outputs */
    private function execute(
        CheckProfile $profile,
        string $batch,
        string $tree,
        array $outputs,
        RedactionContext $context,
    ): ProcessResult {
        $workingDirectory = $profile->workingDirectory === CheckProfileWorkingDirectory::TREE ? $tree : $batch;

        // The allowlist is read from the checker policy instead of being copied,
        // so a configuration change cannot drift between policy and request.
        $allowlist = $this->policies->get(ProcessPolicyName::CHECKER)->environmentAllowlist;
        $environment = array_filter(
            ['AI6_CHECK_PROFILE' => $profile->name, 'LC_ALL' => 'C', 'LANG' => 'C'],
            static fn (string $name): bool => in_array($name, $allowlist, true),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->processes->run(new ProcessRequest(
            $profile->command(),
            $workingDirectory,
            $allowlist,
            $environment,
            $context,
            policy: ProcessPolicyName::CHECKER,
            resultDirectory: $outputs['result'],
            artifactDirectory: $outputs['artifacts'],
        ));
    }

    private function outcome(
        CheckProfile $profile,
        CheckPhase $phase,
        ProcessResult $result,
        string $before,
        string $after,
        RedactionContext $context,
    ): CheckResult {
        [$state, $reason] = $this->interpreter->outcome($profile, $result, $before, $after);

        return new CheckResult(
            $profile->name,
            $phase,
            $state,
            $result->exitCode,
            $reason,
            (int) round($result->durationSeconds * 1000),
            $this->interpreter->safeOutput($result, $context),
            $before,
            $after,
            $profile->declaredMetadata(),
        );
    }

    private function persist(Run $run, CheckResult $result, string $key): CheckResultRecord
    {
        return CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'evidence_epoch' => $run->evidence_epoch,
            'phase' => $result->phase,
            'profile' => $result->profile,
            'state' => $result->state,
            'reason' => $result->reason,
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMilliseconds,
            // The trusted check-log size limit binds at the persistence
            // boundary (SEC-011): the output already crossed the central
            // redaction in the checker role, so the visible cut can expose
            // no secret even where it splits a marker.
            'redacted_output' => $this->retention->limit(RetentionCategory::CHECK_LOGS)->truncate($result->redactedOutput),
            'tree_sha' => $result->treeShaBefore,
            'result_tree_sha' => $result->treeShaAfter,
            'declared_side_effects' => $result->declaredMetadata['side_effects'],
            'declared_network' => $result->declaredMetadata['network'],
            'declared_mutates' => $result->declaredMetadata['mutates'],
            'result_key' => $key,
        ]);
    }

    private function createBatch(): string
    {
        $root = config('ai6.execution_mailboxes.checker_root');
        if (! is_string($root) || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('The checker execution root is unavailable.');
        }

        return $this->createDirectory($root, 'check-');
    }

    /** @return array{root: string, result: string, artifacts: string} */
    private function createOutputs(): array
    {
        $root = config('ai6.execution_mailboxes.checker_output_root');
        if (! is_string($root) || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('The checker output root is unavailable.');
        }

        $batch = $this->createDirectory($root, 'check-');
        foreach (['result', 'artifacts'] as $directory) {
            if (! mkdir($batch.'/'.$directory, 0700)) {
                throw new RuntimeException('A checker output directory could not be created.');
            }
        }

        return ['root' => $batch, 'result' => $batch.'/result', 'artifacts' => $batch.'/artifacts'];
    }

    private function createDirectory(string $root, string $prefix): string
    {
        $path = rtrim(str_replace('\\', '/', $root), '/').'/'.$prefix.bin2hex(random_bytes(8));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('A check execution directory could not be created.');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException('A check execution directory could not be resolved.');
        }

        return str_replace('\\', '/', $real);
    }

    private function remove(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
                $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @chmod($path, 0700);
            @rmdir($path);
        } catch (Throwable) {
            // A leftover temporary directory is a local artifact, never a
            // reason to lose an already produced check result.
        }
    }

    private function removeStrict(string $path): void
    {
        try {
            $this->removeDirectoryStrict($path);
        } catch (Throwable $exception) {
            throw new CheckExecutionBoundaryReached('check_execution_cleanup_failed', $exception);
        }
    }

    private function removeDirectoryStrict(string $path): void
    {
        if (is_link($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('The checker cleanup link could not be removed.');
            }

            return;
        }
        if (! file_exists($path)) {
            return;
        }
        if (! is_dir($path)) {
            throw new RuntimeException('The checker cleanup target is not a directory.');
        }

        @chmod($path, 0770);
        $entries = scandir($path);
        if (! is_array($entries)) {
            throw new RuntimeException('The checker cleanup directory could not be read.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_link($child) || is_file($child)) {
                @chmod($child, 0660);
                if (! unlink($child)) {
                    throw new RuntimeException('The checker cleanup file could not be removed.');
                }
            } else {
                $this->removeDirectoryStrict($child);
            }
        }
        if (! rmdir($path)) {
            throw new RuntimeException('The checker cleanup directory could not be removed.');
        }
    }

    private function cleanupAsyncExecution(ExecutionMailbox $mailbox, string $deliveryId, string $batch, string $executionId): void
    {
        $this->removeStrict($batch);
        $output = config('ai6.execution_mailboxes.checker_output_root');
        if (is_string($output)) {
            $root = rtrim(str_replace('\\', '/', $output), '/');
            $this->removeStrict($root.'/executions/'.$executionId);
            $heartbeat = $root.'/heartbeats/'.$executionId.'.json';
            if ((is_file($heartbeat) || is_link($heartbeat)) && ! unlink($heartbeat)) {
                throw new CheckExecutionBoundaryReached('check_execution_cleanup_failed');
            }
            $claim = $root.'/claims/'.$executionId.'.json';
            if ((is_file($claim) || is_link($claim)) && ! unlink($claim)) {
                throw new CheckExecutionBoundaryReached('check_execution_cleanup_failed');
            }
        }
        $mailbox->cleanupDelivery($deliveryId);
    }
}
