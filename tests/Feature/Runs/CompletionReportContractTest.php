<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\CompletionReportService;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AC-11 of AI6-040: the completion report is a run-type-independent contract.
 *
 * The review-only side is proven in ReviewOnlyRunContractTest; this drives the
 * very same seam from an implementation run, so a run-type assumption inside
 * the projection cannot hide behind the review-only fixture.
 */
final class CompletionReportContractTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai6.run_artifacts.root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-040-report-'.bin2hex(random_bytes(8))]);
        $this->app->forgetInstance(RunArtifactRoot::class);
        $this->app->forgetInstance(RunArtifactStore::class);
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_the_same_report_contract_binds_an_implementation_run(): void
    {
        $fixture = $this->completedApproval('AI6-040-REPORT-IMPL');
        $run = $this->checkpointedImplementationRun($fixture);

        $artifact = $this->app->make(CompletionReportService::class)->build($run);

        self::assertSame(RunArtifactKind::COMPLETION_REPORT, $artifact->kind);
        self::assertSame((string) $run->checkpoint_tree_sha, $artifact->redacted_metadata['checkpoint_tree_sha'] ?? null);
        self::assertSame((string) $run->checkpoint_diff_hash, $artifact->redacted_metadata['diff_hash'] ?? null);
        self::assertTrue($artifact->expires_at->greaterThan(now()->addDays(29)), 'The report carries the shared artifact retention.');

        $report = $this->decoded($artifact->storage_reference);
        self::assertSame('ai6.completion-report.v1', $report['schema']);
        self::assertSame(RunType::IMPLEMENTATION->value, $report['run']['type']);
        self::assertSame($run->id, $report['run']['id']);
        self::assertSame($run->ticket_blob_sha, $report['run']['ticket_blob_sha']);
        self::assertSame($run->ticket_contract_sha256, $report['run']['ticket_contract_sha256']);
        self::assertSame($run->checkpoint_tree_sha, $report['run']['checkpoint_tree_sha']);
        self::assertSame($run->checkpoint_diff_hash, $report['run']['diff_hash']);
        foreach (['slots', 'checks', 'criterion_coverage', 'findings', 'human_decisions', 'gates', 'artifacts'] as $section) {
            self::assertArrayHasKey($section, $report, 'The report keeps every bound section for every run type.');
        }

        // Deterministic: the same authorities reproduce the same artifact.
        self::assertSame($artifact->id, $this->app->make(CompletionReportService::class)->build($run->fresh() ?? $run)->id);
    }

    /** @param array{operator: mixed, project: mixed, approval: mixed} $fixture */
    private function checkpointedImplementationRun(array $fixture): Run
    {
        $run = $this->finalizedRun($fixture);
        self::assertSame(RunType::IMPLEMENTATION, $run->run_type);

        return $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run,
            $run->version,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
        );
    }

    /** @return array<string, mixed> */
    private function decoded(string $storageReference): array
    {
        $path = $this->app->make(RunArtifactRoot::class)->path.DIRECTORY_SEPARATOR.$storageReference;
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
