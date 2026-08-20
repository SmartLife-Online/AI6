<?php

namespace App\AI6\Checks;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\RedactionContext;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Database-free consumer for exactly one checker mailbox request. */
final readonly class CheckerExecutionProcessor
{
    public function __construct(
        private ExecutionMailboxFactory $mailboxes,
        private CheckProfileRegistry $profiles,
        private ControlProcessRunner $processes,
        private ProcessPolicyRegistry $policies,
        private CheckTreeBinding $trees,
        private CheckProcessResultInterpreter $interpreter,
        private CheckerRuntimeConfiguration $runtime,
    ) {}

    /** @param Closure(string): void $heartbeat */
    public function processNext(string $bootId, Closure $heartbeat): bool
    {
        $workspaceRoot = $this->configuredDirectory($this->runtime->workspaceRoot);
        $this->assertWorkspaceEmpty($workspaceRoot);
        $mailbox = $this->mailboxes->forRole(ExecutionRole::CHECKER);
        $message = $mailbox->claimNext(MailboxMessageType::REQUEST, new RedactionContext('checker', null, 'check-request'));
        if ($message === null) {
            return false;
        }

        $request = CheckExecutionRequest::fromJson($message->content);
        if ($message->slotId !== 'check-'.$request->runId || $message->deliveryId !== self::deliveryId($request->executionId)) {
            throw new CheckExecutionContractException('check_request_envelope_binding_invalid');
        }
        if ($request->deadlineAt <= time()) {
            throw new CheckExecutionContractException('check_request_deadline_expired');
        }
        $outputRoot = $this->configuredDirectory('ai6.execution_mailboxes.checker_output_root');
        $claimPath = $outputRoot.'/claims/'.$request->executionId.'.json';
        $this->makeDirectory(dirname($claimPath), 0730);
        $this->atomicJson($claimPath, [
            'schema' => 'ai6.check-claim.v1', 'execution_id' => $request->executionId,
            'checker_boot_id' => $bootId, 'recorded_at' => time(), 'process_started' => false,
        ]);
        $profile = $this->profiles->get($request->profile);
        if (! $profile->allowsPhase($request->phase) || $profile->requiresNetwork) {
            throw new CheckExecutionContractException('check_request_profile_not_allowed');
        }

        $inputRoot = $this->configuredDirectory('ai6.execution_mailboxes.checker_root');
        $source = $inputRoot.'/staged/'.$request->executionId;
        $workspace = $workspaceRoot.'/'.$request->executionId;
        $outputs = $outputRoot.'/executions/'.$request->executionId;

        $this->copyTree($source, $workspace);
        $this->makeDirectory(dirname($outputs), 0730);
        $this->makeDirectory($outputs, 0770);
        $this->makeDirectory($outputs.'/result', 0770);
        $this->makeDirectory($outputs.'/artifacts', 0770);

        $document = null;
        try {
            $tree = $workspace.'/tree';
            $baseline = $workspace.'/baseline';
            if (! hash_equals($request->sourceTreeSha256, $this->trees->hash($source.'/tree'))
                || ! hash_equals($request->baselineSha256, $this->trees->hash($source.'/baseline'))
                || ! hash_equals($request->sourceTreeSha256, $this->trees->hash($tree))
                || ! hash_equals($request->baselineSha256, $this->trees->hash($baseline))) {
                throw new CheckExecutionContractException('check_source_binding_invalid');
            }

            $context = new RedactionContext('checker', $request->runId, 'check:'.$request->phase->value);
            $policy = $this->policies->get(ProcessPolicyName::CHECKER);
            $workingDirectory = $profile->workingDirectory === CheckProfileWorkingDirectory::TREE ? $tree : $workspace;
            $environment = array_filter(
                ['AI6_CHECK_PROFILE' => $profile->name, 'LC_ALL' => 'C', 'LANG' => 'C'],
                static fn (string $name): bool => in_array($name, $policy->environmentAllowlist, true),
                ARRAY_FILTER_USE_KEY,
            );
            $heartbeatPath = $outputRoot.'/heartbeats/'.$request->executionId.'.json';
            $this->makeDirectory(dirname($heartbeatPath), 0730);
            $pulse = function () use ($heartbeat, $heartbeatPath, $request, $bootId): void {
                $heartbeat($request->executionId);
                $this->atomicJson($heartbeatPath, [
                    'schema' => 'ai6.check-heartbeat.v1', 'execution_id' => $request->executionId,
                    'checker_boot_id' => $bootId, 'recorded_at' => time(),
                ]);
            };
            $pulse();
            $running = null;
            $completedAt = null;
            try {
                $running = $this->processes->start(new ProcessRequest(
                    $profile->command(), $workingDirectory, $policy->environmentAllowlist, $environment, $context,
                    timeoutSeconds: max(1, $request->deadlineAt - time()), policy: ProcessPolicyName::CHECKER,
                    resultDirectory: $outputs.'/result', artifactDirectory: $outputs.'/artifacts',
                ));
            } catch (Throwable $exception) {
                if (! $exception instanceof ProcessStartRejectedException) {
                    report($exception);
                }
                $result = $this->interpreter->startFailure($exception);
                $completedAt = time();
            }
            if ($running !== null) {
                $this->atomicJson($claimPath, [
                    'schema' => 'ai6.check-claim.v1', 'execution_id' => $request->executionId,
                    'checker_boot_id' => $bootId, 'recorded_at' => time(), 'process_started' => true,
                ]);
                $result = $running->wait($pulse, $this->runtime->heartbeatIntervalSeconds);
                $completedAt = time();
            }
            $after = $this->trees->hash($tree);
            $baselineAfter = $this->trees->hash($baseline);
            [$state, $reason] = $this->interpreter->outcome(
                $profile, $result, $request->sourceTreeSha256, $after,
                $request->baselineSha256, $baselineAfter,
            );
            $document = new CheckExecutionResultDocument(
                $request->executionId, $request->runId, $request->phase, $request->profile, $bootId,
                $request->deadlineAt, $completedAt, $state, $reason, $result->exitCode,
                (int) round($result->durationSeconds * 1000), $this->interpreter->safeOutput($result, $context),
                $request->sourceTreeSha256, $after, $request->baselineSha256, $baselineAfter,
                $profile->declaredMetadata(),
            );
        } finally {
            $this->removeOwnedWorkspace($workspaceRoot, $workspace, $request->executionId);
        }
        $mailbox->write(MailboxMessageType::RESULT, $message->slotId, $message->deliveryId, $document->toJson());

        return true;
    }

    public static function deliveryId(string $executionId): string
    {
        return hash('sha256', "check-execution\0".$executionId);
    }

    private function configuredDirectory(string $pathOrKey): string
    {
        $path = str_contains($pathOrKey, '/') || str_contains($pathOrKey, '\\') ? $pathOrKey : config($pathOrKey);
        if (! is_string($path) || ! is_dir($path) || is_link($path)) {
            throw new RuntimeException('A checker runtime directory is unavailable.');
        }

        return rtrim(str_replace('\\', '/', (string) realpath($path)), '/');
    }

    private function assertWorkspaceEmpty(string $root): void
    {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                throw new CheckExecutionContractException('checker_workspace_not_empty');
            }
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (! is_dir($source) || is_link($source)) {
            throw new RuntimeException('The staged check source is unavailable.');
        }
        $this->makeDirectory($target);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('The staged check source contains a symlink.');
            }
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen(str_replace('\\', '/', $source)) + 1);
            $destination = $target.'/'.$relative;
            if ($entry->isDir()) {
                $this->makeDirectory($destination);
            } elseif (! copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('The staged check source could not be copied.');
            }
        }
    }

    private function makeDirectory(string $path, int $mode = 0700): void
    {
        $created = ! is_dir($path);
        if ($created && ! mkdir($path, $mode, true)) {
            throw new RuntimeException('A checker runtime directory could not be created.');
        }
        if ($created && ! chmod($path, $mode)) {
            throw new RuntimeException('The checker runtime directory permissions could not be applied.');
        }
    }

    /** @param array<string, scalar> $document */
    private function atomicJson(string $path, array $document): void
    {
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
            || ! chmod($temporary, 0640)
            || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The checker heartbeat could not be published atomically.');
        }
    }

    private function removeOwnedWorkspace(string $root, string $workspace, string $executionId): void
    {
        if (basename($workspace) !== $executionId || dirname($workspace) !== $root) {
            throw new RuntimeException('checker_workspace_binding_invalid');
        }
        if (is_link($workspace)) {
            if (! unlink($workspace)) {
                throw new RuntimeException('checker_workspace_cleanup_failed');
            }

            return;
        }
        if (! is_dir($workspace)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $ok = $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            if (! $ok) {
                throw new RuntimeException('checker_workspace_cleanup_failed');
            }
        }
        if (! rmdir($workspace)) {
            throw new RuntimeException('checker_workspace_cleanup_failed');
        }
    }
}
