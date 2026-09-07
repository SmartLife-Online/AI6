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
            'AgentExecutionRunner $turns',
            'AgentResultValidator $validator',
            'HumanRequestService $humanRequests',
            'RunOrchestrator $orchestrator',
        ] as $seam) {
            self::assertSame(1, substr_count($source, $seam), $seam);
        }
        self::assertStringNotContainsString('new IsolatedTreeExporter', $source);
        self::assertStringNotContainsString('ProcessRequest', $source);
        self::assertStringNotContainsString('ExecutionMailbox', $source);
        self::assertStringNotContainsString('->turn(', $source);
        self::assertStringNotContainsString('Run::query()->update', $source);
    }

    /**
     * The four production consumers of a provider turn — the implementation
     * turn and the three review-family steps — stage and collect exclusively
     * through AgentExecutionRunner. A direct ->turn( call on the adapter, or
     * a second mailbox/process seam, would bypass the one Agent-role staging
     * class this ticket's AC-02/TC-02 binds (AI6-047).
     */
    public function test_every_turn_consumer_reaches_the_provider_only_through_the_single_staging_seam(): void
    {
        foreach ([
            'app/AI6/Runs/RunImplementation.php',
            'app/AI6/Reviews/ReviewRound.php',
            'app/AI6/Reviews/FindingVerificationRound.php',
            'app/AI6/Reviews/SecurityReviewStep.php',
        ] as $path) {
            $source = $this->read($path);
            self::assertSame(1, substr_count($source, 'AgentExecutionRunner $turns'), $path);
            self::assertStringNotContainsString('->turn(', $source, $path);
            self::assertStringNotContainsString('ExecutionMailbox', $source, $path);
            self::assertStringNotContainsString('ProcessRequest', $source, $path);
        }
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
