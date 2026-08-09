<?php

namespace App\AI6\Git;

use FilesystemIterator;
use RuntimeException;

final readonly class ManagedProjectPath
{
    public function __construct(private ControlOperationConfiguration $configuration) {}

    public function prepareAttempt(string $projectIdentifier, string $operationId, int $attempt): string
    {
        $this->assertIdentifiers($projectIdentifier, $operationId, $attempt);
        $root = $this->regularDirectory($this->configuration->managedRoot, create: false);
        $staging = $this->regularDirectory($root.'/.control-staging', create: true);
        $operation = $this->regularDirectory($staging.'/'.$operationId, create: true);

        return $this->regularDirectory($operation.'/'.$attempt, create: true);
    }

    public static function validOperationIdentifier(string $operationId): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $operationId,
        ) === 1;
    }

    public function prepareBundle(string $projectIdentifier, string $operationId, int $attempt): string
    {
        $attemptDirectory = $this->prepareAttempt($projectIdentifier, $operationId, $attempt);

        return $this->regularDirectory($attemptDirectory.'/bundle', create: true);
    }

    public function stagedRepository(string $projectIdentifier, string $operationId, int $attempt): string
    {
        $attemptDirectory = $this->prepareAttempt($projectIdentifier, $operationId, $attempt);

        return $attemptDirectory.DIRECTORY_SEPARATOR.'repository';
    }

    public function repositoryDirectory(string $projectIdentifier): string
    {
        return $this->repositoryPath($projectIdentifier, create: false);
    }

    private function repositoryPath(string $projectIdentifier, bool $create): string
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $projectIdentifier) !== 1) {
            throw new RuntimeException('The managed project identifier is invalid.');
        }

        $root = $this->regularDirectory($this->configuration->managedRoot, create: false);
        $repositoriesPath = $root.'/projects';
        if (! $create && ! file_exists($repositoriesPath) && ! is_link($repositoriesPath)) {
            return $repositoriesPath.DIRECTORY_SEPARATOR.$projectIdentifier.DIRECTORY_SEPARATOR.'repository';
        }
        $repositories = $this->regularDirectory($repositoriesPath, create: $create);
        $this->assertContained($repositories, $root);

        $projectPath = $repositories.'/'.$projectIdentifier;
        if (! $create && ! file_exists($projectPath) && ! is_link($projectPath)) {
            return $projectPath.DIRECTORY_SEPARATOR.'repository';
        }
        $project = $this->regularDirectory($projectPath, create: $create);
        $this->assertContained($project, $root);

        return $project.DIRECTORY_SEPARATOR.'repository';
    }

    public function assertRepository(string $repository): string
    {
        $real = $this->regularDirectory($repository, create: false);
        $root = $this->regularDirectory($this->configuration->managedRoot, create: false);
        $this->assertContained($real, $root);
        $git = $real.DIRECTORY_SEPARATOR.'.git';
        $metadata = file_exists($git) || is_link($git)
            ? $this->regularDirectory($git, create: false)
            : $real;
        $this->assertContained($metadata, $root);
        $this->assertRegularFile($metadata.DIRECTORY_SEPARATOR.'HEAD', $metadata);
        foreach (['objects', 'refs'] as $directory) {
            $resolved = $this->regularDirectory($metadata.DIRECTORY_SEPARATOR.$directory, create: false);
            if (dirname($resolved) !== $metadata) {
                throw new RuntimeException('The managed repository metadata is missing or unsafe.');
            }
        }

        return $real;
    }

    public function publishStagedRepository(
        string $projectIdentifier,
        string $operationId,
        int $attempt,
    ): string {
        $staged = $this->stagedRepository($projectIdentifier, $operationId, $attempt);
        $this->assertRepository($staged);
        $active = $this->repositoryPath($projectIdentifier, create: true);
        $backup = dirname($staged).DIRECTORY_SEPARATOR.'previous-repository';

        $backupExists = file_exists($backup) || is_link($backup);
        if ($backupExists) {
            $this->assertRepository($backup);
        }
        if (file_exists($active) || is_link($active)) {
            if ($backupExists) {
                throw new RuntimeException('The managed repository publish has both active and backup state.');
            }
            $this->assertRepository($active);
            if (! rename($active, $backup)) {
                throw new RuntimeException('The previous managed repository could not be staged for replacement.');
            }
        }

        if (! rename($staged, $active)) {
            if (is_dir($backup) && ! file_exists($active)) {
                @rename($backup, $active);
            }

            throw new RuntimeException('The staged managed repository could not be published.');
        }

        return $this->assertRepository($active);
    }

    public static function attemptRef(string $operationId, int $attempt): string
    {
        if (! self::validOperationIdentifier($operationId) || $attempt < 1) {
            throw new RuntimeException('A managed operation identifier is invalid.');
        }

        return sprintf('refs/ai6/attempts/%s/%d/control', $operationId, $attempt);
    }

    public function activeDirectory(string $projectIdentifier): string
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $projectIdentifier) !== 1) {
            throw new RuntimeException('The managed project identifier is invalid.');
        }

        $root = $this->regularDirectory($this->configuration->managedRoot, create: false);
        $keys = $this->regularDirectory($this->configuration->keyRoot, create: true);
        $this->assertContained($keys, $root);

        return $keys.DIRECTORY_SEPARATOR.$projectIdentifier;
    }

    public function assertRegularFile(string $path, string $parent): void
    {
        $realParent = $this->regularDirectory($parent, create: false);
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || dirname($path) !== $realParent || realpath($path) !== $path) {
            throw new RuntimeException('A managed operation artifact is not a contained regular file.');
        }
    }

    public function removeOwnedAttempt(string $projectIdentifier, string $operationId, int $attempt): void
    {
        $this->assertIdentifiers($projectIdentifier, $operationId, $attempt);
        $directories = $this->ownedOperationDirectory($operationId);
        if ($directories === null) {
            return;
        }
        [$operation] = $directories;

        $target = $operation.'/'.$attempt;
        if (! file_exists($target) && ! is_link($target)) {
            return;
        }

        $this->removeTree($target, $operation);
        if (iterator_count(new FilesystemIterator($operation, FilesystemIterator::SKIP_DOTS)) === 0
            && ! rmdir($operation)) {
            throw new RuntimeException('An empty operation staging directory could not be removed.');
        }
    }

    public function removeOwnedOperation(string $projectIdentifier, string $operationId): void
    {
        $this->assertOperationIdentifiers($projectIdentifier, $operationId);
        $directories = $this->ownedOperationDirectory($operationId);
        if ($directories === null) {
            return;
        }
        [$operation, $staging] = $directories;

        $this->removeTree($operation, $staging);
    }

    /** @return array{string, string}|null */
    private function ownedOperationDirectory(string $operationId): ?array
    {
        if (is_link($this->configuration->managedRoot)) {
            throw new RuntimeException('A managed operation directory is missing or unsafe.');
        }
        $root = realpath($this->configuration->managedRoot);
        if (! is_string($root)) {
            return null;
        }

        $stagingPath = $root.'/.control-staging';
        if (! file_exists($stagingPath) && ! is_link($stagingPath)) {
            return null;
        }
        $staging = $this->regularDirectory($stagingPath, create: false);
        $this->assertContained($staging, $root);

        $operationPath = $staging.'/'.$operationId;
        if (! file_exists($operationPath) && ! is_link($operationPath)) {
            return null;
        }
        $operation = $this->regularDirectory($operationPath, create: false);
        if (dirname($operation) !== $staging) {
            throw new RuntimeException('Managed cleanup escaped its staging directory.');
        }

        return [$operation, $staging];
    }

    private function removeTree(string $path, string $expectedParent): void
    {
        if (dirname($path) !== $expectedParent) {
            throw new RuntimeException('Managed cleanup escaped its owned operation directory.');
        }

        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('An owned operation artifact could not be removed.');
            }

            return;
        }

        if (! is_dir($path)) {
            throw new RuntimeException('An owned operation artifact has an unsupported type.');
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->removeTree($entry->getPathname(), $path);
        }

        if (! rmdir($path)) {
            throw new RuntimeException('An owned operation directory could not be removed.');
        }
    }

    private function regularDirectory(string $path, bool $create): string
    {
        if ($create && ! file_exists($path) && ! mkdir($path, 0700, false) && ! is_dir($path)) {
            throw new RuntimeException('A managed operation directory could not be created.');
        }

        clearstatcache(true, $path);
        if (is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('A managed operation directory is missing or unsafe.');
        }

        $real = realpath($path);
        if (! is_string($real)) {
            throw new RuntimeException('A managed operation directory could not be resolved.');
        }

        return $real;
    }

    private function assertContained(string $path, string $root): void
    {
        if ($path === $root || ! str_starts_with($path.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A managed operation path escaped the configured root.');
        }
    }

    private function assertIdentifiers(string $projectIdentifier, string $operationId, int $attempt): void
    {
        $this->assertOperationIdentifiers($projectIdentifier, $operationId);
        if ($attempt < 1) {
            throw new RuntimeException('A managed operation identifier is invalid.');
        }
    }

    private function assertOperationIdentifiers(string $projectIdentifier, string $operationId): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $projectIdentifier) !== 1
            || ! self::validOperationIdentifier($operationId)) {
            throw new RuntimeException('A managed operation identifier is invalid.');
        }
    }
}
