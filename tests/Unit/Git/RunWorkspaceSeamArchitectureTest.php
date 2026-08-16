<?php

namespace Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * TC-11 and TC-09 at architecture level: exactly one Git execution seam, argument lists only,
 * every effecting run-workspace call under the project effect lock, and a one-way import.
 */
final class RunWorkspaceSeamArchitectureTest extends TestCase
{
    /** @var list<string> */
    private const EFFECTING_METHODS = [
        'createRunWorktree',
        'removeRunWorktree',
        'pruneWorktrees',
        'deleteRunBranch',
        'createRunCheckpoint',
    ];

    /** @var list<string> */
    private const READ_ONLY_METHODS = [
        'resolveRunBranch',
        'resolveTree',
        'canonicalRawDiff',
        'readRunHistory',
        'listRunTreeEntries',
        'readRunBlob',
    ];

    public function test_every_effecting_run_workspace_command_runs_under_the_project_effect_lock(): void
    {
        $source = $this->runnerSource();

        foreach (self::EFFECTING_METHODS as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringContainsString(
                'runEffectingRepositoryCommand(',
                $body,
                $method.'() writes to the managed clone and must hold the project effect lock.',
            );
        }

        foreach (self::READ_ONLY_METHODS as $method) {
            self::assertStringNotContainsString(
                'runEffectingRepositoryCommand(',
                $this->methodBody($source, $method),
                $method.'() is read-only and must not take an effect lock.',
            );
        }

        self::assertStringContainsString(
            'startBlocked(',
            $this->methodBody($source, 'runEffectingRepositoryCommand'),
            'The effecting seam must serialize through the named effect lock.',
        );
        self::assertStringContainsString(
            'gc.auto=0',
            $this->methodBody($source, 'prepareRepositoryCommand'),
            'Automatic maintenance and garbage collection stay disabled for every run.',
        );
    }

    public function test_the_git_execution_seam_exists_exactly_once_and_uses_argument_lists(): void
    {
        $prefixUsers = [];
        $processUsers = [];
        foreach ($this->moduleFiles() as $path => $source) {
            foreach ([
                'shell_exec', 'passthru(', 'proc_open(', 'popen(', 'system(', 'exec(',
            ] as $primitive) {
                self::assertStringNotContainsString($primitive, $source, $path.' must not start a process directly.');
            }
            if (str_contains($source, 'commandPrefix()')) {
                $prefixUsers[] = $path;
            }
            if (str_contains($source, 'new Process(')) {
                $processUsers[] = $path;
            }
        }

        self::assertSame(
            ['app/AI6/Git/HardenedGitEnvironment.php', 'app/AI6/Git/HardenedGitRunner.php'],
            $prefixUsers,
            'Only the hardened environment and its single runner may build the Git command prefix.',
        );
        self::assertSame(
            ['app/AI6/Shared/Process/ControlProcessRunner.php'],
            $processUsers,
            'Only the central control process runner may construct a process.',
        );
    }

    public function test_the_run_workspace_services_reach_git_only_through_the_hardened_runner(): void
    {
        foreach ([
            'app/AI6/Git/RunWorkspaceLifecycle.php',
            'app/AI6/Git/RunCheckpointService.php',
            'app/AI6/Git/RunTreeService.php',
            'app/AI6/Git/RunHistoryContext.php',
        ] as $path) {
            $source = $this->read($path);
            self::assertStringContainsString('HardenedGitRunner', $source, $path.' must consume the single hardened runner.');
            self::assertStringNotContainsString('ProcessRequest', $source, $path.' must not assemble its own process request.');
            self::assertStringNotContainsString('ControlProcessRunner', $source, $path.' must not bypass the hardened runner.');
        }
    }

    public function test_the_import_direction_stays_one_way(): void
    {
        $importer = $this->read('app/AI6/Git/RunPatchImporter.php');
        self::assertStringContainsString('symbolic links', $importer);
        self::assertStringContainsString('approved scope', $importer);

        foreach ($this->moduleFiles() as $path => $source) {
            if (str_starts_with($path, 'app/AI6/Agents/') || str_starts_with($path, 'app/AI6/Checks/')) {
                self::assertStringNotContainsString(
                    'RunPatchImporter',
                    $source,
                    $path.' must not import into the run worktree; only the worker does.',
                );
                self::assertStringNotContainsString('HardenedGitRunner', $source, $path.' must not reach the managed clone.');
            }
        }
    }

    /** @return array<string, string> */
    private function moduleFiles(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/AI6'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $source = file_get_contents($path);
            self::assertIsString($source);
            $files[substr($path, strlen($root) + 1)] = $source;
        }
        ksort($files);

        return $files;
    }

    private function runnerSource(): string
    {
        return $this->read('app/AI6/Git/HardenedGitRunner.php');
    }

    private function read(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$relative);
        self::assertIsString($source, $relative.' must exist.');

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        $position = strpos($source, ' function '.$method.'(');
        self::assertIsInt($position, 'The method '.$method.'() must exist.');
        $start = strpos($source, '{', $position);
        self::assertIsInt($start);

        $depth = 0;
        for ($index = $start; $index < strlen($source); $index++) {
            $depth += match ($source[$index]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }

        self::fail('The method '.$method.'() has no closing brace.');
    }
}
