<?php

namespace Tests\Unit\Reviews;

use PHPUnit\Framework\TestCase;

final class ReviewRoundArchitectureTest extends TestCase
{
    public function test_review_round_consumes_the_single_export_process_validation_and_request_seams(): void
    {
        $source = $this->read('app/AI6/Reviews/ReviewRound.php');

        foreach ([
            'IsolatedTreeExport $exporter',
            'AgentAdapter $adapter',
            'AgentResultValidator $validator',
            'RunArtifactStore $artifacts',
            'HumanRequestService $humanRequests',
            'RunOrchestrator $orchestrator',
        ] as $seam) {
            self::assertSame(1, substr_count($source, $seam), $seam);
        }
        self::assertStringNotContainsString('new IsolatedTreeExporter', $source);
        self::assertStringNotContainsString('ProcessRequest', $source);
        self::assertStringNotContainsString('ExecutionMailbox', $source);
        self::assertStringNotContainsString('Run::query()->update', $source);
    }

    public function test_review_results_are_written_only_through_the_append_only_store(): void
    {
        $round = $this->read('app/AI6/Reviews/ReviewRound.php');
        $store = $this->read('app/AI6/Reviews/ReviewResultStore.php');

        self::assertStringNotContainsString('ReviewResult::query()', $round);
        self::assertStringContainsString('ReviewResult::query()->create', $store);
        self::assertStringNotContainsString('->update(', $store);
        self::assertStringNotContainsString('->delete(', $store);
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($bytes);

        return $bytes;
    }
}
