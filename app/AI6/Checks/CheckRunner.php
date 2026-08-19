<?php

namespace App\AI6\Checks;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\MailboxRejectedException;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
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
    /**
     * Exit code a POSIX runtime uses for a command that could not be executed.
     * The distinction matters: a missing tool is not a failed check.
     */
    private const COMMAND_NOT_FOUND = 127;

    public function __construct(
        private CheckProfileRegistry $profiles,
        private ControlProcessRunner $processes,
        private ExecutionMailboxFactory $mailboxes,
        private IsolatedTreeExporter $exporter,
        private CheckTreeBinding $treeBinding,
        private Redactor $redactor,
        private ProcessPolicyRegistry $policies,
        private SecurityPolicy $security,
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

        try {
            // Writable on purpose: a read-only tree could not be mutated at
            // all, which would make the mutation detection below unfalsifiable.
            $this->exporter->export($worktree, $tree, true);
            if (! mkdir($baseline, 0700)) {
                throw new RuntimeException('The check baseline directory could not be created.');
            }

            $before = $this->treeBinding->hash($tree);
            $key = CheckResult::key($run->id, $phase, $profile->name, $before);
            $this->claimDelivery($run, $phase, $profile, $key, $before, $deliveryAttempt, $context);

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
    ): void {
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
            $this->mailboxes->forRole(ExecutionRole::CHECKER)->write(
                MailboxMessageType::REQUEST,
                'check-'.$run->id,
                self::deliveryId($key, $deliveryAttempt),
                $this->redactor->redact($order, $context)->text,
            );
        } catch (MailboxRejectedException) {
            throw new OrphanedCheckExecution($key, $deliveryAttempt);
        }
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
     * its predecessor and keeps the same four coordinates. Only the row that is
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
        $state = $this->state($profile, $result);
        $reason = $this->reason($state, $result);
        $mutated = ! hash_equals($before, $after);

        if ($mutated && ! $profile->mutatesTree && $state === CheckResultState::SUCCEEDED) {
            // A profile declared as non-mutating that changed the tree anyway is
            // never a green result: the review state would move unnoticed. An
            // already failed, timed out or unavailable check keeps its own more
            // specific reason; its mutation stays visible through the two tree
            // bindings.
            $state = CheckResultState::FAILED;
            $reason = 'unexpected_tree_mutation';
        }

        return new CheckResult(
            $profile->name,
            $phase,
            $state,
            $result->exitCode,
            $reason,
            (int) round($result->durationSeconds * 1000),
            $this->safeOutput($result, $context),
            $before,
            $after,
            $profile->declaredMetadata(),
        );
    }

    private function state(CheckProfile $profile, ProcessResult $result): CheckResultState
    {
        return match (true) {
            $result->outcome === ProcessOutcome::TIMED_OUT => CheckResultState::TIMED_OUT,
            in_array($result->outcome, [ProcessOutcome::START_FAILED, ProcessOutcome::START_REJECTED], true) => CheckResultState::TOOL_UNAVAILABLE,
            $result->exitCode === self::COMMAND_NOT_FOUND || ! $this->executable($profile) => CheckResultState::TOOL_UNAVAILABLE,
            $result->exitCode !== null && in_array($result->exitCode, $profile->successExitCodes, true) => CheckResultState::SUCCEEDED,
            default => CheckResultState::FAILED,
        };
    }

    private function reason(CheckResultState $state, ProcessResult $result): ?string
    {
        if ($state === CheckResultState::SUCCEEDED) {
            return null;
        }
        if ($state === CheckResultState::TIMED_OUT) {
            return 'process_timeout';
        }
        if ($state === CheckResultState::TOOL_UNAVAILABLE) {
            return $result->outcome === ProcessOutcome::START_REJECTED ? 'process_start_rejected' : 'check_tool_unavailable';
        }

        return match ($result->outcome) {
            ProcessOutcome::OUTPUT_LIMIT_EXCEEDED => 'output_limit_exceeded',
            ProcessOutcome::RESOURCE_LIMIT_EXCEEDED => 'resource_limit_exceeded',
            ProcessOutcome::CANCELLED => 'process_cancelled',
            ProcessOutcome::TERMINATION_FAILED => 'process_termination_failed',
            default => 'check_failed',
        };
    }

    private function executable(CheckProfile $profile): bool
    {
        return is_file($profile->program) && is_executable($profile->program);
    }

    /** Every byte a check produced crosses the one central redaction boundary. */
    private function safeOutput(ProcessResult $result, RedactionContext $context): string
    {
        $raw = rtrim($result->output."\n".$result->errorOutput);

        try {
            return $this->redactor->redact($raw, $context)->text;
        } catch (InvalidRedactionInputException) {
            return 'Die Checkausgabe war kein gültiges UTF-8 und wurde verworfen.';
        }
    }

    private function persist(Run $run, CheckResult $result, string $key): CheckResultRecord
    {
        return CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'phase' => $result->phase,
            'profile' => $result->profile,
            'state' => $result->state,
            'reason' => $result->reason,
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMilliseconds,
            'redacted_output' => $result->redactedOutput,
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
}
