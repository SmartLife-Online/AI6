<?php

namespace Tests\Unit\Runs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RunArchitectureTest extends TestCase
{
    public function test_run_state_fields_are_written_only_by_the_orchestrator(): void
    {
        $root = dirname(__DIR__, 3);
        $writers = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (preg_match('/Run::query\(\)[\s\S]{0,250}->(?:create|update|delete)\(/', $source) === 1) {
                $writers[] = $path;
            }
        }

        self::assertSame([
            str_replace('\\', '/', $root.'/app/AI6/Runs/RunOrchestrator.php'),
        ], $writers);
        $controller = file_get_contents($root.'/app/AI6/Runs/RunStartController.php');
        self::assertIsString($controller);
        self::assertStringNotContainsString('HardenedGitRunner', $controller);
        self::assertStringNotContainsString('ControlProcessRunner', $controller);
        self::assertStringContainsString('QueueRunStart', $controller);
    }

    public function test_run_start_is_routed_through_the_recoverable_ticket_status_saga(): void
    {
        $root = dirname(__DIR__, 3);
        $executor = file_get_contents($root.'/app/AI6/Git/ControlOperationExecutor.php');
        $mutation = file_get_contents($root.'/app/AI6/Git/TicketMutationExecutor.php');
        $lease = file_get_contents($root.'/app/AI6/Git/ProjectOperationLease.php');
        foreach ([$executor, $mutation, $lease] as $source) {
            self::assertIsString($source);
        }

        self::assertStringContainsString('ControlOperationType::RUN_START => $this->ticketMutations->advance', $executor);
        self::assertStringContainsString('$this->runs->finalizeClaim(', $mutation);
        self::assertStringContainsString("->whereNull('operation_lock_operation_id')", $mutation);
        self::assertStringContainsString('if (! $runStartLeaseReleased)', $mutation);
        self::assertStringContainsString("->whereNull('pending_control_ref')", $lease);
        self::assertStringContainsString("->whereNull('pending_control_oid')", $lease);
        self::assertStringContainsString("->whereNull('pending_control_operation_id')", $lease);
    }
}
