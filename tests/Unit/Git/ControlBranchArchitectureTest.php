<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlBranchChanger;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\ProjectOperationLease;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

final class ControlBranchArchitectureTest extends TestCase
{
    public function test_claim_publish_generation_and_pending_consumption_have_one_authoritative_seam_each(): void
    {
        $root = dirname(__DIR__, 3);
        $claim = $this->methodSource($root.'/app/AI6/Git/ProjectOperationLease.php', 'claimInitialControlOperation');
        self::assertSame(1, substr_count($claim, '->update(['));
        self::assertStringContainsString("->whereNull('operation_lock_operation_id')", $claim);
        self::assertStringContainsString("->whereNull('active_run_id')", $claim);

        $publish = $this->methodSource($root.'/app/AI6/Git/ControlBranchChanger.php', 'publish');
        self::assertSame(1, substr_count($publish, 'DB::transaction('));
        self::assertStringContainsString("->where('operation_lock_operation_id', \$operation->id)", $publish);
        self::assertStringContainsString("->where('operation_lock_attempt_token', \$attemptToken)", $publish);
        self::assertStringContainsString("->whereNull('active_run_id')", $publish);
        self::assertStringContainsString("'control_generation' => DB::raw('control_generation + 1')", $publish);
        self::assertStringContainsString('ControlBranchAuditEntry::query()->create(', $publish);

        $finalize = $this->methodSource($root.'/app/AI6/Git/ManagedCloneSynchronizer.php', 'finalizeBinding');
        self::assertStringContainsString("'control_oid' => \$targetOid", $finalize);
        self::assertStringContainsString("'pending_control_ref' => null", $finalize);
        self::assertStringContainsString("'pending_control_oid' => null", $finalize);
        self::assertStringContainsString("'pending_control_operation_id' => null", $finalize);
        self::assertSame(1, substr_count($finalize, 'DB::transaction('));

        $generationMethods = [];
        $generationComparisons = [];
        $generationQueueReaders = [];
        $staleMarkerWrites = [];
        foreach ($this->sourceFiles($root.'/app/AI6', static fn (SplFileInfo $file): bool => $file->getExtension() === 'php') as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            if (str_contains($source, 'function isCurrent(')) {
                $generationMethods[] = $file;
            }
            if (preg_match('/(?:control_generation[^\r\n]*(?:===|!==|==|!=|<=|>=|<(?!=)|(?<![=-])>(?!=))|(?:===|!==|==|!=|<=|>=|<(?!=)|(?<![=-])>(?!=))[^\r\n]*control_generation)/', $source) === 1) {
                $generationComparisons[] = $file;
            }
            if (preg_match('/[\'\"](?:is_)?stale(?:_at)?[\'\"]\s*=>/', $source) === 1) {
                $readTimeStatusFiles = [
                    str_replace('\\', '/', $root.'/app/AI6/Projects/ProjectReadModelStatus.php'),
                    str_replace('\\', '/', $root.'/app/AI6/Projects/TicketReadModelFreshness.php'),
                ];
                if (! in_array($file, $readTimeStatusFiles, true)) {
                    $staleMarkerWrites[] = $file;
                }
            }
            if (str_contains($source, 'control_generation')) {
                if (str_contains($source, 'implements ShouldQueue')) {
                    $generationQueueReaders[] = $file;
                    self::assertStringNotContainsString('control_generation + 1', $source, $file);
                }
                self::assertStringNotContainsString('Listener', basename($file), $file);
            }
        }
        $generationFile = str_replace('\\', '/', $root.'/app/AI6/Projects/ControlGeneration.php');
        $configurationApprovalFile = str_replace('\\', '/', $root.'/app/AI6/Projects/Actions/ApproveProjectConfiguration.php');
        $backfillFile = str_replace('\\', '/', $root.'/app/AI6/Tickets/Console/ReprojectUnparsedTicketsCommand.php');
        $approvalPreviewFile = str_replace('\\', '/', $root.'/app/AI6/Runs/Jobs/BuildTicketApprovalPreview.php');
        $runStartFile = str_replace('\\', '/', $root.'/app/AI6/Git/Actions/QueueRunStart.php');
        self::assertSame([$generationFile], $generationMethods);
        self::assertSame([$runStartFile, $configurationApprovalFile, $generationFile, $approvalPreviewFile, $backfillFile], $generationComparisons);
        self::assertSame([$approvalPreviewFile], $generationQueueReaders);
        self::assertSame([], $staleMarkerWrites);

        $freshness = file_get_contents($root.'/app/AI6/Projects/TicketReadModelFreshness.php');
        self::assertIsString($freshness);
        foreach (['DB::', '::query(', '->save(', '->update(', 'Queue::', 'dispatch(', 'control_ref', 'pending_control_ref'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $freshness);
        }

        $status = file_get_contents($root.'/app/AI6/Projects/ProjectReadModelStatus.php');
        self::assertIsString($status);
        foreach (['DB::', '->save(', '->update(', 'Queue::', 'dispatch(', 'control_ref', 'pending_control_ref'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $status);
        }
    }

    public function test_web_entry_points_never_read_repository_content_or_start_git_and_processes(): void
    {
        $root = dirname(__DIR__, 3);
        $files = $this->sourceFiles(
            $root.'/app/AI6',
            static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Controller.php')
                || str_contains(str_replace('\\', '/', $file->getPathname()), '/Livewire/'),
        );
        $files = [
            ...$files,
            ...$this->sourceFiles(
                $root.'/resources/views',
                static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'),
            ),
        ];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:exec|shell_exec|system|passthru|proc_open|popen)\s*\(/',
                $source,
            );
            foreach ([
                'HardenedGitRunner',
                'ControlRemoteProbe',
                'ControlProcessRunner',
                'Symfony\\Component\\Process',
                'Process::',
                'new Process(',
                'file_get_contents(',
                '.git/config',
                '->probeRemote(',
                '->startManagedClone(',
                '->startFetchToAttemptRef(',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    public function test_readme_documents_the_two_compare_and_swap_phases_and_generation_contract(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');
        self::assertIsString($readme);
        foreach ([
            'Control-Branch-Wechsel',
            'Claim',
            'read-only Remote-Probe',
            'Publish',
            'ausstehende Bindung',
            '`control_generation`',
            '`AI6-006E/MG-01`',
            'Base-Commit plus SHA-256',
        ] as $text) {
            self::assertStringContainsString($text, $readme);
        }
    }

    private function methodSource(string $classFile, string $method): string
    {
        require_once $classFile;
        $class = match (basename($classFile)) {
            'ProjectOperationLease.php' => ProjectOperationLease::class,
            'ControlBranchChanger.php' => ControlBranchChanger::class,
            'ManagedCloneSynchronizer.php' => ManagedCloneSynchronizer::class,
            default => throw new \LogicException('Unexpected architecture-test class file.'),
        };
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($classFile);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    /**
     * @param  callable(SplFileInfo): bool  $include
     * @return list<string>
     */
    private function sourceFiles(string $directory, callable $include): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $include($file)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }
}
