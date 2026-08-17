<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
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
    ): ExecutionHome {
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
            $root.'/workspace',
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
            $this->copyTree($exportedTree, $home->workspace);
            $this->materializeInstructions($home, $instructionProfile, $instructionSnapshot);
            $this->writeImmutable($home->runtimeConfiguration, $this->canonicalJson->normalizeAndEncode($runtimeProfile->jsonSerialize())."\n");
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
        } catch (\Throwable $exception) {
            $this->cleanupFailedCreation($home, $exception);
        }

        return $home;
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

    private function copyTree(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new ExecutionHomeException('The exported tree contains a symbolic link.');
            }
            $relative = substr($entry->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $portable = str_replace('\\', '/', $relative);
            $segments = explode('/', $portable);
            if (array_intersect($segments, ['.git', '.codex', '.claude']) !== []
                || in_array(basename($portable), ['AGENTS.md', '.mcp.json', 'mcp.json', '.gitconfig', '.git-credentials'], true)) {
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
