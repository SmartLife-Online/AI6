<?php

namespace App\AI6\Git;

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
        $validated = $this->remotePolicy->validate($remote, $ref, $knownHosts);
        $this->assertRelativePath($destination);
        $variables = $this->environment->variables($privateKey, $knownHosts);

        return $this->processes->run($this->request(
            [
                ...$this->environment->commandPrefix(),
                'clone',
                '--no-checkout',
                '--no-recurse-submodules',
                '--single-branch',
                '--branch', $this->cloneRefName($validated->ref),
                '--', $validated->remote, $destination,
            ],
            $workingDirectory,
            $variables,
            $redactionContext,
        ));
    }

    /**
     * Fetch the validated source ref into FETCH_HEAD without advancing a local ref.
     *
     * Callers must not assume that a subsequent checkout of the source ref observes
     * the fetched commit. Attempt-scoped target refs and their publication belong to
     * the managed-fetch workflow in AI6-006D.
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
}
