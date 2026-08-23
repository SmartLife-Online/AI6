<?php

namespace Tests\Unit\Reviews;

use PHPUnit\Framework\TestCase;

final class FindingArchitectureTest extends TestCase
{
    public function test_effective_blockade_has_one_owner_and_browser_action_starts_no_runtime_boundary(): void
    {
        $root = dirname(__DIR__, 3);
        $owners = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app/AI6'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'function blocks(')) {
                $owners[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        self::assertSame([str_replace('\\', '/', $root.'/app/AI6/Reviews/EffectiveFindingState.php')], $owners);

        foreach (['/app/AI6/Reviews/FindingDispositionController.php', '/app/AI6/Runs/RunTimelinePage.php'] as $path) {
            $contents = (string) file_get_contents($root.$path);
            foreach (['AgentAdapter', 'HardenedGitRunner', 'CheckRunner', 'ProcessRunner'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $contents);
            }
        }
        $controller = (string) file_get_contents($root.'/app/AI6/Reviews/FindingDispositionController.php');
        self::assertStringNotContainsString('ExecutionJob', $controller);
        self::assertStringContainsString('RunOrchestrator', $controller);
        self::assertStringNotContainsString("'fixed'", $controller);
    }

    public function test_original_models_and_dispositions_are_append_only_in_the_database_contract(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 3).'/database/migrations/2026_08_22_000000_add_finding_contract.php');
        foreach (['findings', 'criterion_coverages', 'instruction_recommendations', 'finding_dispositions'] as $table) {
            self::assertStringContainsString("\$this->immutable('{$table}'", $migration);
        }
        self::assertStringContainsString('{$table}_update_guard', $migration);
        self::assertStringContainsString('{$table}_delete_guard', $migration);
        foreach (['findings', 'criterion_coverages', 'instruction_recommendations', 'finding_dispositions'] as $table) {
            self::assertStringContainsString("{$table}_insert_guard", $migration);
        }
    }
}
