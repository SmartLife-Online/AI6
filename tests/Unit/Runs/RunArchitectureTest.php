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
        self::assertStringContainsString('ApprovalClaimStarter', $controller);
    }

    /** TC-11: step state is written by the orchestrator only, never from a browser entry point. */
    public function test_execution_step_state_is_written_only_by_the_orchestrator(): void
    {
        $root = dirname(__DIR__, 3);
        $pattern = '/ExecutionJob::query\(\)[\s\S]{0,250}->(?:create|update|delete|firstOrCreate)\(/';
        self::assertSame(1, preg_match(
            $pattern,
            '<?php ExecutionJob::query()->whereKey(1)->update(["state" => "succeeded"]);',
        ), 'The detection itself must be able to fail.');

        $writers = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (preg_match($pattern, $source) === 1) {
                $writers[] = $path;
            }
        }

        self::assertSame([
            str_replace('\\', '/', $root.'/app/AI6/Runs/RunOrchestrator.php'),
        ], $writers);
    }

    /** TC-11: the read-only run page holds no orchestration and no mutation. */
    public function test_the_run_timeline_page_stays_a_read_surface(): void
    {
        $page = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Runs/RunTimelinePage.php');
        self::assertIsString($page);

        foreach ([
            'RunOrchestrator',
            'ExecuteRunStep',
            'RunStepReconciler',
            'ExecutionStepDispatcher',
            'HardenedGitRunner',
            'ControlProcessRunner',
            '->update(',
            '->create(',
            '->delete(',
            'Queue::',
            'dispatch(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $page, $forbidden);
        }
        self::assertStringContainsString("Gate::authorize('viewRun'", $page);
        self::assertStringContainsString('changedFiles', $page);
        self::assertStringContainsString('decisions', $page);
    }

    public function test_there_is_exactly_one_prompt_catalog_and_renderer(): void
    {
        $root = dirname(__DIR__, 3).'/app/AI6';
        $catalogs = [];
        $renderers = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^final (?:readonly )?class PromptCatalog/m', $source) === 1) {
                $catalogs[] = $file->getPathname();
            }
            if (preg_match('/^final (?:readonly )?class PromptRenderer/m', $source) === 1) {
                $renderers[] = $file->getPathname();
            }
        }
        self::assertCount(1, $catalogs);
        self::assertCount(1, $renderers);
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

        // Since AI6-020 the run-start arm is grouped with the contract
        // amendment; both stay routed through the one recoverable saga.
        self::assertStringContainsString(
            "ControlOperationType::RUN_START,\n                    ControlOperationType::CONTRACT_AMENDMENT => \$this->ticketMutations->advance",
            $executor,
        );
        self::assertStringContainsString('$this->runs->finalizeClaim(', $mutation);
        self::assertStringContainsString("->whereNull('operation_lock_operation_id')", $mutation);
        self::assertStringContainsString('if (! $runStartLeaseReleased)', $mutation);
        self::assertStringContainsString("->whereNull('pending_control_ref')", $lease);
        self::assertStringContainsString("->whereNull('pending_control_oid')", $lease);
        self::assertStringContainsString("->whereNull('pending_control_operation_id')", $lease);
    }
}
