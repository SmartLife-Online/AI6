<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\Redactor;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The one-way import boundary of a run workspace.
 *
 * Only the worker calls this service. It computes the change set from the isolated view, validates
 * every path, type, symbolic link, scope entry, size and the resulting difference, and only then
 * writes into the run worktree. Nothing in the agent or checker context writes into the worktree or
 * the managed clone.
 */
final readonly class RunPatchImporter
{
    private const MAXIMUM_FILE_BYTES = 1048576;

    private const MAXIMUM_TOTAL_BYTES = 16777216;

    private const MAXIMUM_CHANGES = 500;

    public function __construct(private ?Redactor $redactor = null) {}

    /**
     * Compute the validated change set without touching the run worktree.
     *
     * @param  list<string>  $approvedScope  canonical path prefixes the run may change
     * @return list<RunPatchChange>
     */
    public function plan(Run $run, string $source, array $approvedScope): array
    {
        $worktree = $this->boundWorktree($run);
        $sourceRoot = $this->regularDirectory($source);
        if ($sourceRoot === $worktree
            || str_starts_with($sourceRoot.'/', $worktree.'/')
            || str_starts_with($worktree.'/', $sourceRoot.'/')) {
            throw new RuntimeException('The isolated view must not overlap the run worktree.');
        }
        $scope = $this->canonicalScope($approvedScope);

        $candidates = $this->inventory($sourceRoot, rejectGitMetadata: true);
        $present = $this->inventory($worktree, rejectGitMetadata: false);

        $changes = [];
        $total = 0;
        foreach ($candidates as $path => $size) {
            if (! $this->inScope($path, $scope)) {
                throw new RuntimeException('The isolated view changes a path outside the approved scope.');
            }
            if ($size > self::MAXIMUM_FILE_BYTES) {
                throw new RuntimeException('An imported file exceeds the configured size limit.');
            }
            if (! array_key_exists($path, $present)) {
                $this->assertImportableFile($sourceRoot.'/'.$path, allowedBinaryOrigin: false);
                $changes[] = new RunPatchChange($path, RunPatchStatus::ADDED, $size);
                $total += $size;

                continue;
            }
            if (! $this->identical($sourceRoot.'/'.$path, $worktree.'/'.$path)) {
                $this->assertImportableFile($sourceRoot.'/'.$path, allowedBinaryOrigin: $this->isBinaryFile($worktree.'/'.$path));
                $changes[] = new RunPatchChange($path, RunPatchStatus::MODIFIED, $size);
                $total += $size;
            }
        }

        foreach ($present as $path => $size) {
            if (array_key_exists($path, $candidates) || ! $this->inScope($path, $scope)) {
                continue;
            }
            $changes[] = new RunPatchChange($path, RunPatchStatus::DELETED, 0);
        }

        if (count($changes) > self::MAXIMUM_CHANGES) {
            throw new RuntimeException('The computed patch exceeds the configured change limit.');
        }
        if ($total > self::MAXIMUM_TOTAL_BYTES) {
            throw new RuntimeException('The computed patch exceeds the configured total size limit.');
        }

        usort($changes, static fn (RunPatchChange $left, RunPatchChange $right): int => strcmp($left->path, $right->path));

        return $changes;
    }

    /**
     * Validate and then apply the change set to the bound run worktree.
     *
     * @param  list<string>  $approvedScope
     * @return list<RunPatchChange>
     */
    public function import(Run $run, string $source, array $approvedScope): array
    {
        $changes = $this->plan($run, $source, $approvedScope);

        return $this->importChanges($run, $source, $changes);
    }

    /**
     * Apply a change set this importer previously computed via {@see plan()} or
     * {@see partition()}. The one seam that writes into the worktree; a caller
     * that quarantined out-of-scope changes hands only the effective subset in.
     *
     * @param  list<RunPatchChange>  $changes
     * @return list<RunPatchChange>
     */
    public function importChanges(Run $run, string $source, array $changes): array
    {
        $worktree = $this->boundWorktree($run);
        $sourceRoot = $this->regularDirectory($source);
        $backup = $this->backup($worktree, $changes);

        try {
            $this->apply($worktree, $sourceRoot, $changes);
        } catch (\Throwable $exception) {
            $this->restore($worktree, $backup);
            throw $exception;
        }

        return $changes;
    }

    /**
     * Compute the change set like {@see plan()}, but split it by the effective
     * scope instead of failing on the first outside path (TKT-007).
     *
     * Out-of-scope changes — including deletions outside the scope, which
     * {@see plan()} silently ignores — carry the same validation as in-scope
     * ones except the size accounting, so the caller can quarantine them and
     * route each into a scope decision without a second diff path.
     *
     * @param  list<string>  $effectiveScope
     * @return array{in: list<RunPatchChange>, out: list<RunPatchChange>}
     */
    public function partition(Run $run, string $source, array $effectiveScope): array
    {
        $worktree = $this->boundWorktree($run);
        $sourceRoot = $this->regularDirectory($source);
        if ($sourceRoot === $worktree
            || str_starts_with($sourceRoot.'/', $worktree.'/')
            || str_starts_with($worktree.'/', $sourceRoot.'/')) {
            throw new RuntimeException('The isolated view must not overlap the run worktree.');
        }
        $scope = $this->canonicalScope($effectiveScope);

        $candidates = $this->inventory($sourceRoot, rejectGitMetadata: true);
        $present = $this->inventory($worktree, rejectGitMetadata: false);

        $inScope = [];
        $outOfScope = [];
        $total = 0;
        foreach ($candidates as $path => $size) {
            if ($size > self::MAXIMUM_FILE_BYTES) {
                throw new RuntimeException('An imported file exceeds the configured size limit.');
            }
            $exists = array_key_exists($path, $present);
            if ($exists && $this->identical($sourceRoot.'/'.$path, $worktree.'/'.$path)) {
                continue;
            }
            $this->assertImportableFile(
                $sourceRoot.'/'.$path,
                allowedBinaryOrigin: $exists && $this->isBinaryFile($worktree.'/'.$path),
            );
            $change = new RunPatchChange($path, $exists ? RunPatchStatus::MODIFIED : RunPatchStatus::ADDED, $size);
            if ($this->inScope($path, $scope)) {
                $inScope[] = $change;
                $total += $size;
            } else {
                $outOfScope[] = $change;
            }
        }

        foreach ($present as $path => $size) {
            if (array_key_exists($path, $candidates)) {
                continue;
            }
            $change = new RunPatchChange($path, RunPatchStatus::DELETED, 0);
            if ($this->inScope($path, $scope)) {
                $inScope[] = $change;
            } else {
                $outOfScope[] = $change;
            }
        }

        if (count($inScope) + count($outOfScope) > self::MAXIMUM_CHANGES) {
            throw new RuntimeException('The computed patch exceeds the configured change limit.');
        }
        if ($total > self::MAXIMUM_TOTAL_BYTES) {
            throw new RuntimeException('The computed patch exceeds the configured total size limit.');
        }

        $order = static fn (RunPatchChange $left, RunPatchChange $right): int => strcmp($left->path, $right->path);
        usort($inScope, $order);
        usort($outOfScope, $order);

        return ['in' => $inScope, 'out' => $outOfScope];
    }

    /**
     * @param  list<RunPatchChange>  $changes
     * @return array<string, string|null>
     */
    private function backup(string $worktree, array $changes): array
    {
        $backup = [];
        foreach ($changes as $change) {
            $target = $worktree.'/'.$change->path;
            if (! file_exists($target) || is_link($target)) {
                $backup[$change->path] = null;

                continue;
            }
            $bytes = file_get_contents($target);
            if (! is_string($bytes)) {
                throw new RuntimeException('An import backup could not be read.');
            }
            $backup[$change->path] = $bytes;
        }

        return $backup;
    }

    /**
     * @param  list<RunPatchChange>  $changes
     */
    private function apply(string $worktree, string $sourceRoot, array $changes): void
    {
        foreach ($changes as $change) {
            $target = $worktree.'/'.$change->path;
            if ($change->status === RunPatchStatus::DELETED) {
                $this->assertRegularTarget($target);
                if (! unlink($target)) {
                    throw new RuntimeException('An imported deletion could not be applied.');
                }

                continue;
            }

            $this->prepareParent($worktree, $change->path);
            $this->assertRegularTarget($target);
            $bytes = file_get_contents($sourceRoot.'/'.$change->path);
            if (! is_string($bytes) || file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('An imported file could not be written.');
            }
            if (! chmod($target, 0600)) {
                throw new RuntimeException('An imported file could not be sealed.');
            }
        }
    }

    /** @param array<string, string|null> $backup */
    private function restore(string $worktree, array $backup): void
    {
        foreach ($backup as $path => $bytes) {
            $target = $worktree.'/'.$path;
            if ($bytes === null) {
                if (is_file($target) && ! is_link($target)) {
                    @unlink($target);
                }

                continue;
            }
            $this->prepareParent($worktree, $path);
            if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('The import rollback could not restore the previous worktree state.');
            }
            @chmod($target, 0600);
        }
    }

    private function assertImportableFile(string $path, bool $allowedBinaryOrigin): void
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('The import rejects an unreadable file.');
        }
        $validUtf8 = true;
        if ($this->redactor instanceof Redactor) {
            try {
                $this->redactor->assertValidInput($bytes);
            } catch (InvalidRedactionInputException) {
                $validUtf8 = false;
            }
        } else {
            $validUtf8 = preg_match('//u', $bytes) === 1;
        }
        if (! $validUtf8 && ! $allowedBinaryOrigin) {
            throw new RuntimeException('The import rejects an unknown binary without an allowed origin.');
        }
    }

    private function isBinaryFile(string $path): bool
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return false;
        }
        if ($this->redactor instanceof Redactor) {
            try {
                $this->redactor->assertValidInput($bytes);
            } catch (InvalidRedactionInputException) {
                return true;
            }

            return false;
        }

        return preg_match('//u', $bytes) !== 1;
    }

    /**
     * @return array<string, int> canonical relative path => size in bytes
     */
    private function inventory(string $root, bool $rejectGitMetadata): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $isGitMetadata = in_array('.git', explode('/', $relative), true);
            if ($isGitMetadata) {
                if ($rejectGitMetadata) {
                    throw new RuntimeException('The isolated view must not contain Git metadata.');
                }

                continue;
            }
            if (is_link($entry->getPathname())) {
                throw new RuntimeException('The import rejects symbolic links.');
            }
            if ($entry->isDir()) {
                continue;
            }
            if (! $entry->isFile()) {
                throw new RuntimeException('The import rejects special file types.');
            }
            if (RefreshPathPolicy::canonicalBasePath($relative) !== $relative) {
                throw new RuntimeException('The import rejects a non-canonical path.');
            }
            $size = $entry->getSize();
            $files[$relative] = is_int($size) ? $size : 0;
        }

        return $files;
    }

    /**
     * @param  list<string>  $approvedScope
     * @return list<string>
     */
    private function canonicalScope(array $approvedScope): array
    {
        if ($approvedScope === []) {
            throw new RuntimeException('The import requires an approved scope.');
        }

        $scope = [];
        foreach ($approvedScope as $entry) {
            if (RefreshPathPolicy::canonicalBasePath($entry) !== $entry) {
                throw new RuntimeException('The approved scope contains a non-canonical path.');
            }
            $scope[] = $entry;
        }

        return $scope;
    }

    /** @param list<string> $scope */
    private function inScope(string $path, array $scope): bool
    {
        foreach ($scope as $entry) {
            if ($path === $entry || str_starts_with($path, $entry.'/')) {
                return true;
            }
        }

        return false;
    }

    private function identical(string $left, string $right): bool
    {
        $leftBytes = file_get_contents($left);
        $rightBytes = file_get_contents($right);

        return is_string($leftBytes) && is_string($rightBytes) && hash_equals(
            hash('sha256', $leftBytes),
            hash('sha256', $rightBytes),
        );
    }

    private function prepareParent(string $worktree, string $path): void
    {
        $segments = explode('/', $path);
        array_pop($segments);
        $current = $worktree;
        foreach ($segments as $segment) {
            $current .= '/'.$segment;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new RuntimeException('The import rejects a symbolic link inside the target path.');
            }
            if (! file_exists($current) && ! mkdir($current, 0700)) {
                throw new RuntimeException('An imported directory could not be created.');
            }
            if (! is_dir($current)) {
                throw new RuntimeException('The import target path is not a directory.');
            }
        }
    }

    private function assertRegularTarget(string $target): void
    {
        clearstatcache(true, $target);
        if (is_link($target)) {
            throw new RuntimeException('The import rejects an existing symbolic link target.');
        }
        if (file_exists($target) && ! is_file($target)) {
            throw new RuntimeException('The import rejects a non-regular target.');
        }
    }

    private function boundWorktree(Run $run): string
    {
        if ($run->worktree_path === null) {
            throw new RuntimeException('The import requires a bound run workspace.');
        }

        return $this->regularDirectory($run->worktree_path);
    }

    private function regularDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('An import directory is unavailable or unsafe.');
        }

        return str_replace('\\', '/', $real);
    }
}
