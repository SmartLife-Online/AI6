<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ScopeReconciliation;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ScopeReconciliationTest extends TestCase
{
    public function test_only_paths_not_covered_by_exact_or_directory_scope_are_unresolved(): void
    {
        $unresolved = (new ScopeReconciliation)->unresolved(
            ['app/AI6/Runs/A.php', 'README.md', 'tests/New.php', 'app/AI6/Runs/A.php'],
            ['app/AI6/Runs', 'README.md'],
        );

        self::assertSame(['tests/New.php'], $unresolved);
    }

    public function test_review_readiness_has_exactly_one_application_decision_and_no_browser_mutation_path(): void
    {
        $root = dirname(__DIR__, 3).'/app/AI6';
        $decisions = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $decisions += substr_count($source, 'function reviewReadiness(');
        }

        self::assertSame(1, $decisions);
        $page = file_get_contents($root.'/Runs/RunTimelinePage.php');
        self::assertIsString($page);
        self::assertStringNotContainsString('RunCheckpointService', $page);
        self::assertStringNotContainsString('recordReviewReadiness', $page);
        self::assertStringNotContainsString('authorizeGateEvidence', $page);
    }
}
