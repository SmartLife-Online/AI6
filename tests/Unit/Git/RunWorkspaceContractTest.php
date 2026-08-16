<?php

namespace Tests\Unit\Git;

use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\RunBranchName;
use App\AI6\Git\RunPatchImporter;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\After;
use RuntimeException;
use Tests\TestCase;

/**
 * TC-01, TC-05, TC-07, TC-08 and TC-09 at contract level: branch grammar, canonical diff parsing,
 * the export negative matrix, the symlink and path negatives and the one-way import boundary.
 */
final class RunWorkspaceContractTest extends TestCase
{
    private ?string $fixture = null;

    #[After]
    public function removeFixture(): void
    {
        if ($this->fixture !== null && is_dir($this->fixture)) {
            $this->remove($this->fixture);
        }
        $this->fixture = null;
    }

    public function test_run_branch_is_deterministic_and_kept_in_its_own_namespace(): void
    {
        $project = str_repeat('a', 32);
        $run = '123e4567-e89b-42d3-a456-426614174000';
        $branch = RunBranchName::forRun($project, $run);

        self::assertSame('refs/heads/ai6/runs/'.$project.'/'.$run, $branch->value);
        self::assertSame('ai6/runs/'.$project.'/'.$run, $branch->shortName());

        $candidates = [
            'refs/heads/main',
            'refs/heads/ai6/runs/'.$project,
            'refs/heads/ai6/runs/'.$project.'/not-a-run-identifier',
            'refs/heads/ai6/runs/'.strtoupper($project).'/'.$run,
            'refs/ai6/runs/'.$project.'/'.$run,
        ];
        $rejected = [];
        foreach ($candidates as $candidate) {
            try {
                new RunBranchName($candidate);
            } catch (InvalidArgumentException) {
                $rejected[] = $candidate;
            }
        }

        self::assertSame($candidates, $rejected, 'Every name outside the run namespace must be rejected.');
    }

    public function test_export_omits_git_metadata_at_every_depth_and_publishes_read_only_files(): void
    {
        $source = $this->fixture().'/source';
        $destination = $this->fixture().'/export';
        self::assertTrue(mkdir($source.'/nested/.git/hooks', 0700, true));
        self::assertNotFalse(file_put_contents($source.'/.git', 'gitdir: /private/common'));
        self::assertNotFalse(file_put_contents($source.'/nested/.git/config', "[remote]\n"));
        self::assertNotFalse(file_put_contents($source.'/nested/.git/hooks/post-checkout', "#!/bin/sh\n"));
        self::assertNotFalse(file_put_contents($source.'/nested/file.txt', 'safe'));

        (new IsolatedTreeExporter)->export($source, $destination);

        self::assertFileExists($destination.'/nested/file.txt');
        foreach ([
            $destination.'/.git',
            $destination.'/nested/.git',
            $destination.'/nested/.git/config',
            $destination.'/nested/.git/hooks/post-checkout',
        ] as $metadata) {
            self::assertFileDoesNotExist($metadata, 'Git metadata must not be reachable in the export.');
        }
        self::assertFalse(is_writable($destination.'/nested/file.txt'));
    }

    public function test_export_rejects_a_symlink_before_publishing_any_output(): void
    {
        $source = $this->fixture().'/source';
        $destination = $this->fixture().'/export';
        self::assertTrue(mkdir($source, 0700, true));
        self::assertNotFalse(file_put_contents($source.'/safe.txt', 'safe'));
        if (! @symlink($source.'/safe.txt', $source.'/unsafe-link')) {
            self::markTestSkipped('Creating symlinks is unavailable in this runtime.');
        }

        try {
            (new IsolatedTreeExporter)->export($source, $destination);
            self::fail('The export accepted a symbolic link.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($destination, 'A rejected export must leave no partial result.');
        }
    }

    public function test_canonical_diff_hash_binds_mode_path_and_object_and_stays_stable(): void
    {
        $hasher = $this->app->make(CanonicalDiffHasher::class);
        $context = new RedactionContext('project', 'run', 'test-diff');
        $old = str_repeat('a', 64);
        $new = str_repeat('b', 64);
        $raw = ':100644 100755 '.$old.' '.$new." M\0src/file.php\0";

        $first = $hasher->fromRaw($raw, $context);
        self::assertSame($first->hash, $hasher->fromRaw($raw, $context)->hash);
        self::assertNotSame($first->hash, $hasher->fromRaw(':100644 100644 '.$old.' '.$new." M\0src/file.php\0", $context)->hash);
        self::assertNotSame($first->hash, $hasher->fromRaw(':100644 100755 '.$old.' '.$new." M\0src/other.php\0", $context)->hash);

        $unsafe = [
            ':100644 100755 '.$old.' '.$new." M\0../escape.php\0",
            ':100644 100755 '.$old.' '.$new." M\0/absolute.php\0",
            ":100644 100755 aaa bbb M\0src/file.php\0",
        ];
        $rejected = [];
        foreach ($unsafe as $entry) {
            try {
                $hasher->fromRaw($entry, $context);
            } catch (RuntimeException) {
                $rejected[] = $entry;
            }
        }

        self::assertSame($unsafe, $rejected, 'Traversing, absolute and abbreviated entries must be rejected.');
    }

    public function test_the_import_applies_only_validated_in_scope_changes_to_the_bound_worktree(): void
    {
        $worktree = $this->fixture().'/worktree';
        $view = $this->fixture().'/isolated-view';
        self::assertTrue(mkdir($worktree.'/src', 0700, true));
        self::assertTrue(mkdir($view.'/src', 0700, true));
        self::assertNotFalse(file_put_contents($worktree.'/src/kept.php', 'kept'));
        self::assertNotFalse(file_put_contents($worktree.'/src/removed.php', 'removed'));
        self::assertNotFalse(file_put_contents($worktree.'/outside.php', 'untouched'));
        self::assertNotFalse(file_put_contents($view.'/src/kept.php', 'changed'));
        self::assertNotFalse(file_put_contents($view.'/src/added.php', 'added'));

        $changes = (new RunPatchImporter)->import($this->boundRun($worktree), $view, ['src']);

        self::assertSame(
            ['src/added.php:added', 'src/kept.php:modified', 'src/removed.php:deleted'],
            array_map(static fn ($change): string => $change->path.':'.$change->status->value, $changes),
        );
        self::assertSame('changed', file_get_contents($worktree.'/src/kept.php'));
        self::assertSame('added', file_get_contents($worktree.'/src/added.php'));
        self::assertFileDoesNotExist($worktree.'/src/removed.php');
        self::assertSame('untouched', file_get_contents($worktree.'/outside.php'));
    }

    public function test_the_import_rejects_out_of_scope_metadata_symlink_and_traversing_sources(): void
    {
        $worktree = $this->fixture().'/worktree';
        $view = $this->fixture().'/isolated-view';
        self::assertTrue(mkdir($worktree, 0700, true));
        self::assertTrue(mkdir($view.'/src', 0700, true));
        self::assertNotFalse(file_put_contents($view.'/src/in-scope.php', 'ok'));
        self::assertNotFalse(file_put_contents($view.'/escaped.php', 'nope'));
        $importer = new RunPatchImporter;
        $run = $this->boundRun($worktree);

        try {
            $importer->plan($run, $view, ['src']);
            self::fail('The import accepted a path outside the approved scope.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside the approved scope', $exception->getMessage());
        }
        self::assertFileDoesNotExist($worktree.'/escaped.php');

        self::assertTrue(unlink($view.'/escaped.php'));
        self::assertTrue(mkdir($view.'/.git', 0700));
        self::assertNotFalse(file_put_contents($view.'/.git/config', "[remote]\n"));
        try {
            $importer->plan($run, $view, ['src']);
            self::fail('The import accepted Git metadata in the isolated view.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Git metadata', $exception->getMessage());
        }
    }

    public function test_the_import_refuses_a_view_that_overlaps_the_run_worktree(): void
    {
        $worktree = $this->fixture().'/worktree';
        self::assertTrue(mkdir($worktree.'/src', 0700, true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not overlap the run worktree');
        (new RunPatchImporter)->plan($this->boundRun($worktree), $worktree.'/src', ['src']);
    }

    public function test_the_import_rejects_a_symlink_in_the_isolated_view(): void
    {
        $worktree = $this->fixture().'/worktree';
        $view = $this->fixture().'/isolated-view';
        self::assertTrue(mkdir($worktree, 0700, true));
        self::assertTrue(mkdir($view.'/src', 0700, true));
        self::assertNotFalse(file_put_contents($view.'/src/real.php', 'ok'));
        if (! @symlink($view.'/src/real.php', $view.'/src/link.php')) {
            self::markTestSkipped('Creating symlinks is unavailable in this runtime.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        (new RunPatchImporter)->plan($this->boundRun($worktree), $view, ['src']);
    }

    public function test_an_unbound_run_has_no_import_target(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bound run workspace');
        (new RunPatchImporter)->plan(new Run, $this->fixture(), ['src']);
    }

    private function boundRun(string $worktree): Run
    {
        $run = new Run;
        $run->worktree_path = $worktree;

        return $run;
    }

    private function fixture(): string
    {
        if ($this->fixture === null) {
            $path = sys_get_temp_dir().'/ai6-run-workspace-unit-'.bin2hex(random_bytes(8));
            self::assertTrue(mkdir($path, 0700, true));
            $this->fixture = $path;
        }

        return $this->fixture;
    }

    private function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.'/'.$entry;
            @chmod($child, is_dir($child) && ! is_link($child) ? 0700 : 0600);
            if (is_dir($child) && ! is_link($child)) {
                $this->remove($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}
