<?php

namespace App\AI6\Git;

use App\AI6\Shared\Process\BlockedProcessStartResult;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\RedactionContext;
use InvalidArgumentException;

final class HardenedGitRunner
{
    public function __construct(
        private readonly ControlProcessRunner $processes,
        private readonly GitRemotePolicy $remotePolicy,
        private readonly HardenedGitEnvironment $environment,
    ) {}

    /** @throws GitRemoteRejected */
    public function clone(
        string $remote,
        string $ref,
        string $destination,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        RedactionContext $redactionContext,
    ): ProcessResult {
        return $this->processes->run($this->cloneRequest(
            $remote, $ref, $destination, $workingDirectory, $privateKey, $knownHosts, $redactionContext,
        ));
    }

    public function startManagedClone(
        string $remote,
        string $ref,
        string $destination,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
        string $effectLockName,
    ): BlockedProcessStartResult {
        return $this->processes->startBlocked($this->managedCloneRequest(
            $remote, $ref, $destination, $workingDirectory, $privateKey, $knownHosts, $expectedHostKeyFingerprint, $redactionContext,
        ), $effectLockName);
    }

    public function managedCloneArgumentHash(
        string $remote,
        string $ref,
        string $destination,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
    ): string {
        return $this->requestHash($this->managedCloneRequest(
            $remote, $ref, $destination, $workingDirectory, $privateKey, $knownHosts, $expectedHostKeyFingerprint, $redactionContext,
        ));
    }

    public function probeRemote(
        string $remote,
        string $ref,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
    ): ProcessResult {
        $validated = $this->remotePolicy->validate($remote, $ref, $knownHosts, $expectedHostKeyFingerprint);
        $variables = $this->environment->variables($privateKey, $knownHosts);

        return $this->processes->run($this->request([
            ...$this->environment->commandPrefix(),
            'ls-remote', '--refs', '--exit-code', '--', $validated->remote, $validated->ref,
        ], $workingDirectory, $variables, $redactionContext));
    }

    public function startFetchToAttemptRef(
        string $remote,
        string $sourceRef,
        string $attemptRef,
        string $repository,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
        string $effectLockName,
    ): BlockedProcessStartResult {
        return $this->processes->startBlocked($this->fetchAttemptRequest(
            $remote, $sourceRef, $attemptRef, $repository, $privateKey, $knownHosts, $expectedHostKeyFingerprint, $redactionContext,
        ), $effectLockName);
    }

    public function fetchArgumentHash(
        string $remote,
        string $sourceRef,
        string $attemptRef,
        string $repository,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
    ): string {
        return $this->requestHash($this->fetchAttemptRequest(
            $remote, $sourceRef, $attemptRef, $repository, $privateKey, $knownHosts, $expectedHostKeyFingerprint, $redactionContext,
        ));
    }

    /**
     * Fetch the validated source ref into FETCH_HEAD without advancing a local ref.
     *
     * This legacy helper is prohibited for managed-clone operations because it writes
     * shared FETCH_HEAD state and has no attempt-scoped target ref. Managed fetches
     * must use startFetchToAttemptRef() and publish the resulting ref separately.
     *
     * @throws GitRemoteRejected
     */
    public function fetch(
        string $remote,
        string $ref,
        string $repository,
        string $privateKey,
        string $knownHosts,
        RedactionContext $redactionContext,
    ): ProcessResult {
        $validated = $this->remotePolicy->validate($remote, $ref, $knownHosts);
        $variables = $this->environment->variables($privateKey, $knownHosts);
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            return $preflight;
        }

        return $this->processes->run($this->request(
            [
                ...$this->environment->commandPrefix(),
                ...$preflight,
                'fetch', '--no-recurse-submodules', '--no-tags', '--', $validated->remote, $validated->ref,
            ],
            $repository,
            $variables,
            $redactionContext,
        ));
    }

    public function checkout(string $repository, string $ref, RedactionContext $redactionContext): ProcessResult
    {
        $this->remotePolicy->validateRef($ref);
        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            return $preflight;
        }

        return $this->processes->run($this->request(
            [
                ...$this->environment->commandPrefix(),
                ...$preflight,
                'checkout', '--force', '--no-recurse-submodules', $ref,
            ],
            $repository,
            $variables,
            $redactionContext,
        ));
    }

    public function resolveRef(string $repository, string $ref, RedactionContext $redactionContext): ProcessResult
    {
        $this->remotePolicy->validateRef($ref);

        return $this->resolveRepositoryRef($repository, $ref, $redactionContext);
    }

    public function resolveAttemptRef(string $repository, string $attemptRef, RedactionContext $redactionContext): ProcessResult
    {
        $this->assertAttemptRef($attemptRef);

        return $this->resolveRepositoryRef($repository, $attemptRef, $redactionContext);
    }

    public function readRegularBlob(
        string $repository,
        string $controlCommit,
        string $relativePath,
        RedactionContext $redactionContext,
    ): TicketBlob {
        $this->assertOid($controlCommit);
        if (RefreshPathPolicy::canonicalBasePath($relativePath) !== $relativePath) {
            throw new ControlOperationTerminalConflict(
                'refresh_path_not_canonical',
                'Der Refresh-Pfad ist nicht kanonisch.',
            );
        }

        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            throw new ControlOperationTerminalConflict(
                'refresh_repository_configuration_rejected',
                'Die Git-Konfiguration des verwalteten Repositorys wurde abgelehnt.',
            );
        }

        $entry = $this->processes->run($this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            'ls-tree', '-z', '--full-tree', $controlCommit, '--', $relativePath,
        ], $repository, $variables, $redactionContext));
        if (! $entry->succeeded()) {
            throw new ControlOperationTerminalConflict(
                'refresh_tree_lookup_failed',
                'Der angeforderte Git-Pfad konnte nicht sicher aufgelöst werden.',
            );
        }

        $record = $entry->output;
        if (! str_ends_with($record, "\0") || substr_count($record, "\0") !== 1) {
            throw new ControlOperationTerminalConflict(
                'refresh_path_missing_or_ambiguous',
                'Der angeforderte Pfad bezeichnet keinen eindeutigen regulären Blob.',
            );
        }
        $record = substr($record, 0, -1);
        $tab = strpos($record, "\t");
        if ($tab === false) {
            throw new ControlOperationTerminalConflict(
                'refresh_tree_entry_malformed',
                'Git lieferte einen ungültigen Baumeintrag.',
            );
        }
        $metadata = substr($record, 0, $tab);
        $returnedPath = substr($record, $tab + 1);
        if (preg_match('/\A(100644|100755) blob ([0-9a-f]{64})\z/D', $metadata, $matches) !== 1
            || ! hash_equals($relativePath, $returnedPath)) {
            throw new ControlOperationTerminalConflict(
                'refresh_path_not_regular_blob',
                'Der angeforderte Pfad ist kein case-genauer regulärer Blob.',
            );
        }

        $blobSha = $matches[2];
        $blob = $this->processes->run($this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            'cat-file', 'blob', $blobSha,
        ], $repository, $variables, $redactionContext));
        if (! $blob->succeeded()) {
            throw new ControlOperationTerminalConflict(
                'refresh_blob_read_failed',
                'Der gebundene Git-Blob konnte nicht sicher gelesen werden.',
            );
        }

        return new TicketBlob($returnedPath, $blobSha, $blob->output);
    }

    private function resolveRepositoryRef(string $repository, string $ref, RedactionContext $redactionContext): ProcessResult
    {
        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            return $preflight;
        }

        return $this->processes->run($this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            'rev-parse', '--quiet', '--verify', $ref.'^{commit}',
        ], $repository, $variables, $redactionContext));
    }

    public function updateRef(
        string $repository,
        string $ref,
        string $targetOid,
        ?string $expectedOid,
        RedactionContext $redactionContext,
    ): ProcessResult {
        $this->remotePolicy->validateRef($ref);
        $this->assertOid($targetOid);
        if ($expectedOid !== null) {
            $this->assertOid($expectedOid);
        }
        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            return $preflight;
        }
        $command = [
            ...$this->environment->commandPrefix(), ...$preflight,
            '-c', 'core.logAllRefUpdates=false',
            'update-ref', '--no-deref', $ref, $targetOid,
        ];
        $command[] = $expectedOid ?? str_repeat('0', 64);

        return $this->processes->run($this->request($command, $repository, $variables, $redactionContext));
    }

    public function deleteAttemptRef(
        string $repository,
        string $attemptRef,
        string $expectedOid,
        RedactionContext $redactionContext,
    ): ProcessResult {
        $this->assertAttemptRef($attemptRef);
        $this->assertOid($expectedOid);
        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            return $preflight;
        }

        return $this->processes->run($this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            '-c', 'core.logAllRefUpdates=false',
            'update-ref', '--no-deref', '-d', $attemptRef, $expectedOid,
        ], $repository, $variables, $redactionContext));
    }

    /** @return array<string, string> */
    public function refs(string $repository, RedactionContext $redactionContext): array
    {
        $variables = $this->environment->variables();
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            throw new InvalidArgumentException($preflight->errorOutput);
        }
        $result = $this->processes->run($this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            'for-each-ref', '--format=%(refname)%00%(objectname)',
        ], $repository, $variables, $redactionContext));
        if (! $result->succeeded()) {
            throw new InvalidArgumentException($result->errorOutput);
        }

        $refs = [];
        foreach (preg_split('/\r?\n/', trim($result->output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\0", $line, 2);
            if (count($parts) !== 2 || ! $this->validOid($parts[1])) {
                throw new InvalidArgumentException('Git returned a malformed ref inventory.');
            }
            $refs[$parts[0]] = $parts[1];
        }

        return $refs;
    }

    private function cloneRequest(
        string $remote,
        string $ref,
        string $destination,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        RedactionContext $redactionContext,
    ): ProcessRequest {
        $validated = $this->remotePolicy->validate($remote, $ref, $knownHosts);
        $this->assertRelativePath($destination);
        $variables = $this->environment->variables($privateKey, $knownHosts);

        return $this->request([
            ...$this->environment->commandPrefix(),
            '-c', 'gc.auto=0', '-c', 'maintenance.auto=false', '-c', 'core.logAllRefUpdates=false',
            'clone', '--no-checkout', '--no-recurse-submodules', '--no-tags', '--single-branch',
            '--branch', $this->cloneRefName($validated->ref),
            '--', $validated->remote, $destination,
        ], $workingDirectory, $variables, $redactionContext);
    }

    private function fetchAttemptRequest(
        string $remote,
        string $sourceRef,
        string $attemptRef,
        string $repository,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
    ): ProcessRequest {
        $validated = $this->remotePolicy->validate($remote, $sourceRef, $knownHosts, $expectedHostKeyFingerprint);
        $this->assertAttemptRef($attemptRef);
        $variables = $this->environment->variables($privateKey, $knownHosts);
        $preflight = $this->repositoryConfiguration($repository, $variables, $redactionContext);
        if ($preflight instanceof ProcessResult) {
            throw new InvalidArgumentException($preflight->errorOutput);
        }

        return $this->request([
            ...$this->environment->commandPrefix(), ...$preflight,
            '-c', 'gc.auto=0', '-c', 'maintenance.auto=false', '-c', 'core.logAllRefUpdates=false',
            'fetch', '--no-write-fetch-head', '--no-auto-maintenance', '--no-recurse-submodules', '--no-tags',
            '--', $validated->remote, '+'.$validated->ref.':'.$attemptRef,
        ], $repository, $variables, $redactionContext);
    }

    private function managedCloneRequest(
        string $remote,
        string $ref,
        string $destination,
        string $workingDirectory,
        string $privateKey,
        string $knownHosts,
        string $expectedHostKeyFingerprint,
        RedactionContext $redactionContext,
    ): ProcessRequest {
        $validated = $this->remotePolicy->validate($remote, $ref, $knownHosts, $expectedHostKeyFingerprint);
        $this->assertRelativePath($destination);
        $variables = $this->environment->variables($privateKey, $knownHosts);

        return $this->request([
            ...$this->environment->commandPrefix(),
            '-c', 'gc.auto=0', '-c', 'maintenance.auto=false', '-c', 'core.logAllRefUpdates=false',
            'clone', '--bare', '--no-recurse-submodules', '--no-tags', '--single-branch',
            '--branch', $this->cloneRefName($validated->ref),
            '--', $validated->remote, $destination,
        ], $workingDirectory, $variables, $redactionContext);
    }

    private function requestHash(ProcessRequest $request): string
    {
        return hash('sha256', json_encode($request->command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $variables
     * @return list<string>|ProcessResult
     */
    private function repositoryConfiguration(string $repository, array $variables, RedactionContext $context): array|ProcessResult
    {
        $worktreeConfig = $this->processes->run($this->request(
            [
                ...$this->environment->commandPrefix(),
                'config', '--local', '--type=bool', '--get', 'extensions.worktreeConfig',
            ],
            $repository,
            $variables,
            $context,
        ));
        if ($worktreeConfig->succeeded() && trim($worktreeConfig->output) === 'true') {
            return $this->configurationRejected($worktreeConfig->durationSeconds);
        }
        if (! $worktreeConfig->succeeded()
            && ! ($worktreeConfig->outcome === ProcessOutcome::FAILED && $worktreeConfig->exitCode === 1 && $worktreeConfig->output === '')) {
            return $worktreeConfig;
        }

        $probe = $this->processes->run($this->request(
            [
                ...$this->environment->commandPrefix(),
                'config', '--local', '--includes', '--name-only', '--null', '--get-regexp',
                '^(filter\..*\.(clean|smudge|process|required)|diff\..*\.textconv|core\.(sshcommand|ask[Pp]ass|hookspath|fsmonitor|pager)|credential\.helper|gpg(\.[^.]+)?\.program|user\.signingkey|pager\..*)$',
            ],
            $repository,
            $variables,
            $context,
        ));

        if ($probe->outcome === ProcessOutcome::FAILED && $probe->exitCode === 1 && $probe->output === '') {
            return [];
        }

        if (! $probe->succeeded()) {
            return $probe;
        }

        $keys = array_values(array_filter(explode("\0", $probe->output), static fn (string $key): bool => $key !== ''));
        $arguments = [];
        foreach ($keys as $key) {
            $key = strtolower($key);
            if (preg_match('/\A(?:core\.(?:sshcommand|askpass)|gpg(?:\.[^.]+)?\.program|user\.signingkey)\z/D', $key) === 1) {
                return $this->configurationRejected($probe->durationSeconds);
            }

            if (preg_match('/\Afilter\..*\.required\z/D', $key) === 1) {
                $arguments[] = '-c';
                $arguments[] = $key.'=false';
            } elseif (preg_match('/\A(?:filter\..*\.(?:clean|smudge|process)|diff\..*\.textconv|core\.pager|pager\..*)\z/D', $key) === 1) {
                $arguments[] = '-c';
                $arguments[] = $key.'=';
            }
        }

        return $arguments;
    }

    private function configurationRejected(float $durationSeconds): ProcessResult
    {
        return new ProcessResult(ProcessOutcome::FAILED, null, '', 'Repository Git configuration was rejected.', $durationSeconds);
    }

    /** @param non-empty-list<string> $command
     * @param  array<string, string>  $variables
     */
    private function request(array $command, string $workingDirectory, array $variables, RedactionContext $context): ProcessRequest
    {
        return new ProcessRequest(
            $command,
            $workingDirectory,
            array_keys($variables),
            $variables,
            $context,
        );
    }

    private function assertRelativePath(string $path): void
    {
        if (preg_match('/\A[A-Za-z0-9._-]+\z/D', $path) !== 1 || $path === '.' || $path === '..') {
            throw new InvalidArgumentException('The Git destination path is invalid.');
        }
    }

    private function cloneRefName(string $ref): string
    {
        foreach (['refs/heads/', 'refs/tags/'] as $prefix) {
            if (str_starts_with($ref, $prefix)) {
                return substr($ref, strlen($prefix));
            }
        }

        throw new InvalidArgumentException('The validated Git clone ref has no supported namespace.');
    }

    private function assertAttemptRef(string $ref): void
    {
        if (preg_match('/\Arefs\/ai6\/attempts\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\/[1-9][0-9]*\/control\z/D', $ref) !== 1) {
            throw new InvalidArgumentException('The attempt-scoped Git ref is invalid.');
        }
    }

    private function assertOid(string $oid): void
    {
        if (! $this->validOid($oid)) {
            throw new InvalidArgumentException('The Git object identifier is invalid.');
        }
    }

    private function validOid(string $oid): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/D', $oid) === 1;
    }
}
