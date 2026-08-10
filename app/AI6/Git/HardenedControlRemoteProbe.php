<?php

namespace App\AI6\Git;

use App\AI6\Projects\Models\Project;
use App\AI6\Shared\Redaction\RedactionContext;
use RuntimeException;

final readonly class HardenedControlRemoteProbe implements ControlRemoteProbe
{
    public function __construct(
        private ControlOperationConfiguration $configuration,
        private HardenedGitRunner $git,
    ) {}

    public function resolve(Project $project, string $ref, RedactionContext $context): string
    {
        if ($project->remote === null
            || $project->deploy_key_reference === null
            || $project->host_key_fingerprint === null
            || ! in_array($ref, $this->configuration->managedRefAllowlist, true)) {
            throw new RuntimeException('The project remote probe contract is incomplete.');
        }

        $root = realpath($this->configuration->managedRoot);
        if (! is_string($root) || is_link($this->configuration->managedRoot) || ! is_dir($root)) {
            throw new RuntimeException('The managed root is unavailable or unsafe.');
        }

        $result = $this->git->probeRemote(
            $project->remote,
            $ref,
            $root,
            $project->deploy_key_reference,
            $this->configuration->knownHostsFile,
            $project->host_key_fingerprint,
            $context,
        );
        if (! $result->succeeded()) {
            if ($result->exitCode === 2
                && trim($result->output) === ''
                && trim($result->errorOutput) === '') {
                throw new ControlRemoteRefUnresolved('The control ref does not exist on the remote.');
            }

            throw new RuntimeException(
                $result->errorOutput !== ''
                    ? $result->errorOutput
                    : 'The control ref remote probe failed.',
            );
        }

        $lines = preg_split('/\r?\n/', trim($result->output)) ?: [];
        if (count($lines) !== 1) {
            throw new ControlRemoteRefUnresolved('The control ref remote probe returned no unique result.');
        }

        $parts = preg_split('/\s+/', $lines[0], 2);
        if (! is_array($parts)
            || count($parts) !== 2
            || $parts[1] !== $ref
            || preg_match('/\A[0-9a-f]{64}\z/D', $parts[0]) !== 1) {
            throw new ControlRemoteRefUnresolved('The control ref remote probe returned a malformed binding.');
        }

        return $parts[0];
    }
}
