<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\ScopePathLimitExceeded;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\QueryException;
use Tests\Feature\Tickets\TicketUiTestCase;

/** TC-02, TC-03, TC-04, TC-05 */
final class ScopeDecisionTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_an_approved_path_extends_the_bound_effective_scope_once(): void
    {
        $fixture = $this->completedApproval('AI6-020-TC08');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);

        $updated = $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/Extra.php', true, null, 12, $canonicalJson);

        self::assertSame(1, $updated->added_scope_paths_count);
        self::assertContains('app/AI6/Runs/Extra.php', $updated->effective_scope_snapshot);
        self::assertSame(1, ScopeDecision::query()->where('run_id', $run->id)->where('outcome', 'approved')->count());
    }

    /** TC-04 */
    public function test_a_rejected_path_is_recorded_but_never_extends_the_scope(): void
    {
        $fixture = $this->completedApproval('AI6-020-TC08-REJ');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);

        $updated = $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/Rejected.php', false, null, 12, $canonicalJson);

        self::assertSame(0, $updated->added_scope_paths_count);
        self::assertNull($updated->effective_scope_snapshot);
        self::assertSame('rejected', ScopeDecision::query()->where('run_id', $run->id)->first()->outcome);
    }

    /** TC-03 idempotency: the same path decided twice consumes the counter once. */
    public function test_the_same_path_decided_twice_does_not_double_count(): void
    {
        $fixture = $this->completedApproval('AI6-020-TC03');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);

        $first = $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/Retry.php', true, null, 12, $canonicalJson);
        $second = $orchestrator->applyScopeDecision($run->fresh(), 'app/AI6/Runs/Retry.php', true, null, 12, $canonicalJson);

        self::assertSame(1, $first->added_scope_paths_count);
        self::assertSame(1, $second->added_scope_paths_count);
        self::assertSame(1, ScopeDecision::query()->where('run_id', $run->id)->count());
    }

    /** TC-02: exactly at the limit succeeds, one past it is refused without a partial effect. */
    public function test_exceeding_the_limit_records_nothing_and_throws(): void
    {
        $fixture = $this->completedApproval('AI6-020-TC02');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);

        $run = $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/One.php', true, null, 1, $canonicalJson);
        self::assertSame(1, $run->added_scope_paths_count);

        try {
            $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/Two.php', true, null, 1, $canonicalJson);
            self::fail('Exceeding max_added_scope_paths must throw.');
        } catch (ScopePathLimitExceeded $exceeded) {
            self::assertSame(2, $exceeded->observed);
            self::assertSame(1, $exceeded->maximum);
        }

        self::assertSame(1, $run->fresh()->added_scope_paths_count);
        self::assertSame(0, ScopeDecision::query()->where('run_id', $run->id)->where('path', 'app/AI6/Runs/Two.php')->count());
    }

    /** Decisions are an immutable audit trail (§ Review Focus: invalidation heißt unwirksam, nicht gelöscht). */
    public function test_scope_decisions_cannot_be_updated(): void
    {
        $fixture = $this->completedApproval('AI6-020-TC08-IMM');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $orchestrator->applyScopeDecision($run, 'app/AI6/Runs/Immutable.php', true, null, 12, $canonicalJson);
        $decision = ScopeDecision::query()->where('run_id', $run->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $decision->forceFill(['outcome' => 'rejected'])->save();
    }
}
