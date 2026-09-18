<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ControlProcessRunner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final readonly class ExecutionHomeManager
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private CredentialRevisionRegistry $credentialRevisions,
        private ?ProviderRuntimeProfileRegistry $runtimeProfiles = null,
        private ?InstructionProfileRegistry $instructionProfiles = null,
        private ?AgentInputLimits $inputLimits = null,
    ) {}

    /** @template T
     * @param  \Closure(ExecutionHome): T  $operation
     * @return T
     */
    public function withAgentCredentials(ExecutionHome $home, string $alias, string $generation, \Closure $operation): mixed
    {
        return app(ProviderCredentialStore::class)->withProjection($home, $alias, $generation, $operation);
    }

    /** Central, credential-free native probe preparation in agent-private storage.
     * @template T
     *
     * @param  \Closure(ExecutionHome, InstructionSnapshot): T  $operation
     * @return T
     */
    public function withProbeHome(string $alias, string $runtimeId, \Closure $operation): mixed
    {
        ProviderOnboarding::assertAgent();
        ProviderOnboarding::filename($alias);
        $directory = ProviderOnboarding::path('private_root').'/probe-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new ExecutionHomeException('The private probe directory is unavailable.');
        }
        try {
            foreach (['inputs', 'outputs', 'export'] as $name) {
                if (! mkdir($directory.'/'.$name, 0700)) {
                    throw new ExecutionHomeException('The private probe directory is unavailable.');
                }
            }
            $manager = new self($this->canonicalJson, new CredentialRevisionRegistry([$alias => 'doctor']), $this->runtimeProfiles, $this->instructionProfiles, $this->inputLimits);
            $snapshot = new InstructionSnapshot($alias, [], hash('sha256', 'provider-doctor'));
            $home = $manager->create($directory.'/inputs', $directory.'/outputs', 'doctor', null, $directory.'/export',
                app(InstructionProfileRegistry::class)->get($alias), $snapshot,
                app(ProviderRuntimeProfileRegistry::class)->get($runtimeId), new CredentialProjection($alias, 'doctor', []));

            $boot = app(ProviderCapabilityReport::class)->boot();

            return app(ControlProcessRunner::class)->withinAgentScope(
                new AgentProcessScope([$home->root], [$home->outputRoot],
                    fn () => app(ProviderCapabilityPublisher::class)->pulse($boot)),
                fn () => $operation($home, $snapshot),
            );
        } finally {
            app(ProviderCredentialStore::class)->cleanup($directory);
        }
    }

    public function create(
        string $executionRoot,
        string $outputRoot,
        string $slotId,
        ?string $sessionId,
        string $exportedTree,
        InstructionResolutionProfile $instructionProfile,
        InstructionSnapshot $instructionSnapshot,
        ProviderRuntimeProfile $runtimeProfile,
        CredentialProjection $credentials,
        bool $writableWorkspace = false,
        ?AgentResultContext $turnContext = null,
    ): ExecutionHome {
        if ($credentials->files !== []) {
            ProviderOnboarding::assertAgent();
        }
        $this->assertId($slotId);
        if ($sessionId !== null) {
            $this->assertId($sessionId);
        }
        if ($instructionSnapshot->providerProfileAlias !== $instructionProfile->providerProfileAlias
            || $credentials->providerProfileAlias !== $instructionProfile->providerProfileAlias) {
            throw new ExecutionHomeException('The execution bindings do not select one provider profile.');
        }
        $this->credentialRevisions->assertCurrent($credentials);
        if ($this->runtimeProfiles !== null && ! hash_equals($this->runtimeProfiles->get($runtimeProfile->id)->hash, $runtimeProfile->hash)) {
            throw new ExecutionHomeException('The provider runtime profile is not server-bound.');
        }
        if ($this->instructionProfiles !== null
            && $this->instructionProfiles->get($instructionProfile->providerProfileAlias)->discoveries !== $instructionProfile->discoveries) {
            throw new ExecutionHomeException('The instruction resolution profile is not server-bound.');
        }
        $this->assertInputLimits($instructionSnapshot);
        if (! is_dir($executionRoot) || is_link($executionRoot) || ! is_dir($outputRoot) || is_link($outputRoot)
            || realpath($executionRoot) === realpath($outputRoot) || ! is_dir($exportedTree) || is_link($exportedTree)) {
            throw new ExecutionHomeException('The isolated execution root or exported tree is unavailable.');
        }

        $name = $slotId.'-'.($sessionId ?? 'new').'-'.bin2hex(random_bytes(8));
        $root = rtrim($executionRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
        $writableRoot = rtrim($outputRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
        if (! mkdir($root, 0700)) {
            throw new ExecutionHomeException('The isolated execution home could not be created.');
        }
        if (! mkdir($writableRoot, 01730)) {
            rmdir($root);
            throw new ExecutionHomeException('The isolated execution output root could not be created.');
        }
        if (! chmod($writableRoot, 01730)) {
            rmdir($writableRoot);
            rmdir($root);
            throw new ExecutionHomeException('The isolated execution output root permissions could not be applied.');
        }

        $home = new ExecutionHome(
            $root,
            $writableRoot,
            ($writableWorkspace ? $writableRoot : $root).'/workspace',
            $root.'/home',
            $root.'/instructions',
            $root.'/runtime/profile.json',
            $root.'/home/auth',
            $writableRoot.'/result',
            $writableRoot.'/artifacts',
            $writableRoot.'/instruction-patch',
        );

        try {
            foreach ([$home->workspace, $home->home, $home->instructionOverlay, dirname($home->runtimeConfiguration), $home->authDirectory] as $directory) {
                if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                    throw new ExecutionHomeException('An isolated execution directory could not be created.');
                }
            }
            foreach ([$home->resultDirectory, $home->artifactDirectory, $home->patchDirectory] as $directory) {
                if (! is_dir($directory) && ! mkdir($directory, 01730, true)) {
                    throw new ExecutionHomeException('An isolated writable execution directory could not be created.');
                }
                if (! chmod($directory, 01730)) {
                    throw new ExecutionHomeException('Isolated writable execution directory permissions could not be applied.');
                }
            }
            $projection = $this->copyTree($exportedTree, $home->workspace, $instructionProfile->providerProfileAlias);
            $this->materializeInstructions($home, $instructionProfile, $instructionSnapshot);
            foreach ($instructionSnapshot->entries as $entry) {
                $projection[$entry->repositoryPath] = $entry->contentSha256;
            }
            if ($writableWorkspace) {
                $this->makeWorkspaceWritable($home->workspace, $projection);
            }
            $this->writeImmutable($home->runtimeConfiguration, $this->canonicalJson->normalizeAndEncode($runtimeProfile->jsonSerialize())."\n");
            if ($instructionProfile->providerProfileAlias === GitHubCopilotCliAdapter::PROVIDER_ALIAS) {
                $this->writeImmutable($home->home.'/settings.json', GitHubCopilotCliConfiguration::settingsBytes($runtimeProfile, $this->canonicalJson));
                if (! mkdir($home->home.'/session-state', 0700)) {
                    throw new ExecutionHomeException('The native session directory could not be created.');
                }
            }
            if ($instructionProfile->providerProfileAlias === GrokCliAdapter::PROVIDER_ALIAS) {
                $this->writeImmutable($home->home.'/config.toml', GrokCliConfiguration::settingsBytes($runtimeProfile));
                $this->writeImmutable($home->home.'/sandbox.toml', GrokCliConfiguration::sandboxBytes());
                $this->writeImmutable($home->home.'/hooks-paths', '');
                // The agent creates its private session target (0700) in the output
                // directory when the invocation starts; no group-read permission is added.
                if (! mkdir($home->home.'/hooks', 0700) || ! mkdir($home->resultDirectory.'/grok', 01730)
                    || ! chmod($home->resultDirectory.'/grok', 01730)) {
                    throw new ExecutionHomeException('The Grok invocation directories could not be created.');
                }
            }
            if ($turnContext !== null) {
                if ($turnContext->runtimeProfile->hash !== $runtimeProfile->hash
                    || $turnContext->instructionSnapshot->hash !== $instructionSnapshot->hash
                    || $turnContext->slotId !== $slotId) {
                    throw new ExecutionHomeException('The turn context is not bound to the execution home.');
                }
                $this->writeImmutable(dirname($home->runtimeConfiguration).'/turn.json', $turnContext->toJson());
            }
            foreach ($credentials->files as $target => $source) {
                $destination = $home->authDirectory.'/'.$target;
                $directory = dirname($destination);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                    throw new ExecutionHomeException('A credential projection directory could not be created.');
                }
                $bytes = file_get_contents($source);
                if (! is_string($bytes)) {
                    throw new ExecutionHomeException('A credential projection could not be read.');
                }
                $this->writeImmutable($destination, $bytes);
            }
            $this->sealTree($home->root);
            if ($instructionProfile->providerProfileAlias === GrokCliAdapter::PROVIDER_ALIAS) {
                // Add only this server-owned link; never traverse it while sealing inputs.
                if (! chmod($home->home, 0750)) {
                    throw new ExecutionHomeException('The native session link could not be prepared.');
                }
                try {
                    if (! symlink($home->resultDirectory.'/grok-sessions', $home->home.'/sessions')) {
                        throw new ExecutionHomeException('The native session link could not be created.');
                    }
                } finally {
                    if (! chmod($home->home, 0550)) {
                        throw new ExecutionHomeException('The native home could not be sealed.');
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanupFailedCreation($home, $exception);
        }

        return new ExecutionHome(
            $home->root,
            $home->outputRoot,
            $home->workspace,
            $home->home,
            $home->instructionOverlay,
            $home->runtimeConfiguration,
            $home->authDirectory,
            $home->resultDirectory,
            $home->artifactDirectory,
            $home->patchDirectory,
            $writableWorkspace ? $projection : [],
        );
    }

    public function assertWorkspaceProjection(ExecutionHome $home): void
    {
        if (is_link($home->workspace) || ! is_dir($home->workspace)) {
            throw new ExecutionHomeException('The execution workspace is unavailable.');
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home->workspace, FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new ExecutionHomeException('The execution workspace contains a symbolic link or special file.');
            }
        }
        foreach ($home->workspaceProjection as $path => $expectedHash) {
            $target = $home->workspace.'/'.$path;
            if ($expectedHash === null) {
                if (file_exists($target) || is_link($target)) {
                    throw new ExecutionHomeException('An omitted execution file was changed by the provider.');
                }
            } elseif (! is_file($target) || is_link($target) || ! hash_equals($expectedHash, (string) hash_file('sha256', $target))) {
                throw new ExecutionHomeException('The native instruction snapshot was changed by the provider.');
            }
        }
    }

    /** Restore projection-only differences after the provider exits, before the worker computes its patch. */
    public function restoreWorkspaceProjection(ExecutionHome $home, string $exportedTree): void
    {
        $this->assertWorkspaceProjection($home);
        foreach ($home->workspaceProjection as $path => $expectedHash) {
            $target = $home->workspace.'/'.$path;
            $original = $exportedTree.'/'.$path;
            // Snapshot bytes may differ from the repository bytes, or be absent
            // from it. Neither that overlay nor omission is an agent change.
            if (is_file($original)) {
                $parent = dirname($target);
                if ((! is_dir($parent) && ! mkdir($parent, 0770, true))
                    || (is_file($target) && ! chmod($target, 0660))
                    || ! copy($original, $target) || ! chmod($target, 0660)) {
                    throw new ExecutionHomeException('The execution projection could not be restored for import.');
                }
            } elseif ($expectedHash !== null && (! chmod($target, 0660) || ! unlink($target))) {
                throw new ExecutionHomeException('The execution projection could not be removed for import.');
            }
        }
    }

    public function destroy(ExecutionHome $home): void
    {
        $failed = false;
        foreach ([$home->root, $home->outputRoot] as $root) {
            if (! file_exists($root) && ! is_link($root)) {
                continue;
            }
            if (! is_dir($root) || is_link($root)) {
                $failed = true;

                continue;
            }

            try {
                if (! $this->tryFilesystemOperation(static fn (): bool => chmod($root, 0700))) {
                    $failed = true;
                }
                $directories = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($directories as $entry) {
                    if ($entry->isDir() && ! $entry->isLink()
                        && ! $this->tryFilesystemOperation(static fn (): bool => chmod($entry->getPathname(), 0700))) {
                        $failed = true;
                    }
                }
                unset($entry);
                unset($directories);

                $entries = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($entries as $entry) {
                    if ($entry->isDir() && ! $entry->isLink()) {
                        $removed = $this->tryFilesystemOperation(static fn (): bool => rmdir($entry->getPathname()));
                    } else {
                        if (DIRECTORY_SEPARATOR !== '/') {
                            $this->tryFilesystemOperation(static fn (): bool => chmod($entry->getPathname(), 0600));
                        }
                        $removed = $this->tryFilesystemOperation(static fn (): bool => unlink($entry->getPathname()));
                    }
                    if (! $removed) {
                        $failed = true;
                    }
                }
                unset($entry);
                unset($entries);
                if (! $this->tryFilesystemOperation(static fn (): bool => rmdir($root))) {
                    $failed = true;
                }
            } catch (\Throwable) {
                $failed = true;
            }
        }

        if ($failed || file_exists($home->root) || is_link($home->root) || file_exists($home->outputRoot) || is_link($home->outputRoot)) {
            throw new ExecutionHomeException(sprintf(
                'The isolated execution home could not be destroyed completely (operation=%s, input=%s, output=%s).',
                $failed ? 'failed' : 'complete',
                file_exists($home->root) || is_link($home->root) ? 'present' : 'absent',
                file_exists($home->outputRoot) || is_link($home->outputRoot) ? 'present' : 'absent',
            ));
        }
    }

    private function materializeInstructions(ExecutionHome $home, InstructionResolutionProfile $profile, InstructionSnapshot $snapshot): void
    {
        $allowed = array_keys($profile->discoveries);
        foreach ($snapshot->entries as $entry) {
            if (! in_array($entry->discoveryName, $allowed, true)) {
                throw new ExecutionHomeException('The instruction snapshot exceeds its resolution profile.');
            }
            $this->assertRelativePath($entry->repositoryPath);
            if (! hash_equals($entry->contentSha256, hash('sha256', $entry->effectiveContent))) {
                throw new ExecutionHomeException('The instruction snapshot content binding is invalid.');
            }
            $overlay = $home->instructionOverlay.'/'.$entry->repositoryPath;
            $native = $home->workspace.'/'.$entry->repositoryPath;
            foreach ([dirname($overlay), dirname($native)] as $directory) {
                if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                    throw new ExecutionHomeException('An instruction overlay directory could not be created.');
                }
            }
            $this->writeImmutable($overlay, $entry->effectiveContent);
            $this->writeImmutable($native, $entry->effectiveContent);
        }
    }

    /** @return array<string, null> */
    private function copyTree(string $source, string $target, string $providerAlias): array
    {
        $omitted = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new ExecutionHomeException('The exported tree contains a symbolic link.');
            }
            $relative = substr($entry->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $portable = str_replace('\\', '/', $relative);
            $segments = explode('/', $portable);
            // AGENTS.override.md is read by native Codex discovery in preference
            // to AGENTS.md (AI6-033); like AGENTS.md it only ever exists as the
            // bound snapshot, never as repository bytes. `.agents` is the
            // vendor-neutral extension root of the pinned Codex version — it
            // carries `skills/`, `hooks.json` and `plugins/marketplace.json` —
            // and joins `.codex`/`.claude` as a directory a managed repository
            // may carry but no approved runtime profile ever activates (AGT-009).
            if (($providerAlias === GrokCliAdapter::PROVIDER_ALIAS && (array_intersect($segments, ['.grok', '.cursor']) !== []
                    || in_array(basename($portable), [...GrokCliConfiguration::DISCOVERY_NAMES, '.envrc'], true)))
                || array_intersect($segments, ['.git', '.agents', '.codex', '.claude']) !== []
                || in_array(basename($portable), ['AGENTS.md', 'AGENTS.override.md', '.mcp.json', 'mcp.json', '.gitconfig', '.git-credentials'], true)) {
                if ($entry->isFile()) {
                    $omitted[$portable] = null;
                }

                continue;
            }
            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            if ($entry->isDir()) {
                if (! is_dir($destination) && ! mkdir($destination, 0700, true)) {
                    throw new ExecutionHomeException('The exported tree directory could not be copied.');
                }
            } else {
                $directory = dirname($destination);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                    throw new ExecutionHomeException('The exported tree directory could not be copied.');
                }
                if (! copy($entry->getPathname(), $destination)) {
                    throw new ExecutionHomeException('The exported tree file could not be copied.');
                }
            }
        }

        return $omitted;
    }

    /** @param array<string, string|null> $projection */
    private function makeWorkspaceWritable(string $root, array $projection): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $mode = $entry->isDir() ? 0770 : (isset($projection[$relative]) ? 0440 : 0660);
            if (! chmod($entry->getPathname(), $mode)) {
                throw new ExecutionHomeException('The writable workspace permissions could not be applied.');
            }
        }
        if (! chmod($root, 0770)) {
            throw new ExecutionHomeException('The writable workspace root permissions could not be applied.');
        }
    }

    private function writeImmutable(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path, 0440)) {
            throw new ExecutionHomeException('An immutable execution file could not be materialized.');
        }
    }

    private function sealTree(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                if (! chmod($entry->getPathname(), 0440)) {
                    throw new ExecutionHomeException('A read-only execution file could not be sealed.');
                }
            } elseif (! chmod($entry->getPathname(), 0550)) {
                throw new ExecutionHomeException('A read-only execution directory could not be sealed.');
            }
        }
        if (! chmod($root, 0550)) {
            throw new ExecutionHomeException('The read-only execution root could not be sealed.');
        }
    }

    private function cleanupFailedCreation(ExecutionHome $home, \Throwable $creationFailure): never
    {
        try {
            $this->destroy($home);
        } catch (ExecutionHomeException) {
            throw new ExecutionHomeException(
                'The isolated execution home creation failed and cleanup was incomplete.',
                previous: $creationFailure,
            );
        }

        throw $creationFailure;
    }

    /** @param callable(): bool $operation */
    private function tryFilesystemOperation(callable $operation): bool
    {
        try {
            return $operation();
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertId(string $id): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $id) !== 1) {
            throw new ExecutionHomeException('An execution binding identifier is invalid.');
        }
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains('/'.str_replace('\\', '/', $path).'/', '/../')) {
            throw new ExecutionHomeException('An instruction snapshot path is invalid.');
        }
    }

    private function assertInputLimits(InstructionSnapshot $snapshot): void
    {
        if ($this->inputLimits === null) {
            return;
        }
        if (count($snapshot->entries) > $this->inputLimits->maxInstructionFiles) {
            throw new ExecutionHomeException('The instruction snapshot exceeds its configured file limit.');
        }
        $total = 0;
        foreach ($snapshot->entries as $entry) {
            $bytes = strlen($entry->effectiveContent);
            $total += $bytes;
            if ($bytes > $this->inputLimits->maxInstructionFileBytes
                || count($entry->imports) > $this->inputLimits->maxInstructionImportDepth) {
                throw new ExecutionHomeException('The instruction snapshot exceeds its configured per-file limit.');
            }
        }
        if ($total > $this->inputLimits->maxInstructionTotalBytes) {
            throw new ExecutionHomeException('The instruction snapshot exceeds its configured total limit.');
        }
    }
}
