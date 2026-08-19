<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-06: after a scope or contract change, dependent evidence is ineffective
 * while the original results stay readable and untouched (AC-10).
 */
final class EvidenceInvalidationTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    private function checkpointedRun(string $ticketId): Run
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $fixture = $this->completedApproval($ticketId);
        $run = $this->finalizedRun($fixture);

        return $this->bindRunWorkspace($fixture, $run);
    }

    public function test_a_scope_extension_invalidates_the_checkpoint_without_touching_it(): void
    {
        $run = $this->checkpointedRun('AI6-020-INV-SCOPE');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        self::assertSame(0, $run->evidence_epoch);
        self::assertSame(0, $run->checkpoint_evidence_epoch);
        self::assertTrue($orchestrator->hasEffectiveCheckpoint($run));
        $checkpoint = [$run->checkpoint_commit_sha, $run->checkpoint_tree_sha, $run->checkpoint_diff_hash];

        $updated = $orchestrator->applyScopeDecision($run, 'docs/added.md', true, null, 12, $this->app->make(CanonicalJson::class), 'human_approved');

        self::assertSame(1, $updated->evidence_epoch);
        self::assertFalse($orchestrator->hasEffectiveCheckpoint($updated));
        // The original stays readable and byte-identical; only its
        // effectiveness is gone.
        self::assertSame($checkpoint, [$updated->checkpoint_commit_sha, $updated->checkpoint_tree_sha, $updated->checkpoint_diff_hash]);
        self::assertSame(1, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'evidence.invalidated')->count());
    }

    public function test_a_rejected_scope_decision_does_not_invalidate_evidence(): void
    {
        $run = $this->checkpointedRun('AI6-020-INV-REJ');
        $orchestrator = $this->app->make(RunOrchestrator::class);

        $updated = $orchestrator->applyScopeDecision($run, 'docs/rejected.md', false, null, 12, $this->app->make(CanonicalJson::class), 'human_approved');

        self::assertSame(0, $updated->evidence_epoch);
        self::assertTrue($orchestrator->hasEffectiveCheckpoint($updated));
    }

    public function test_a_contract_amendment_invalidates_evidence_again(): void
    {
        $run = $this->checkpointedRun('AI6-020-INV-AMEND');
        $orchestrator = $this->app->make(RunOrchestrator::class);

        $amended = $orchestrator->applyContractAmendment(
            $run,
            str_repeat('d', 64),
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['ticket_files' => ['app/Example.php'], 'project_scope' => []],
            str_repeat('3', 64),
            (array) $run->config_snapshot,
            (string) $run->config_hash,
            (array) $run->prompt_snapshot,
            (string) $run->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );

        self::assertSame(1, $amended->evidence_epoch);
        self::assertFalse($orchestrator->hasEffectiveCheckpoint($amended));
        self::assertSame(1, RunEvent::query()->where('run_id', $run->id)
            ->where('event_type', 'evidence.invalidated')->count());
    }
}
