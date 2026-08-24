<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentScenario;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\FindingStatus;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewResultStore;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Feature\Runs\BuildsFixLoopFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-04 and the persistence half of TC-07.
 *
 * The schema half of TC-07 stays in tests/Unit/Reviews/FindingStatusValidationTest.php;
 * the persistence half needs the real run fixture and therefore lives here, because
 * tests/Unit/ in this repository is deliberately database-free.
 */
final class ReReviewCompletenessTest extends TicketUiTestCase
{
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    /** TC-04: a first-round nothing_to_fix counts neither as result nor as coverage for a changed tree. */
    public function test_an_earlier_nothing_to_fix_is_never_reused_for_a_changed_tree(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC04');
        $run = $prepared['run'];
        $identifier = (string) Project::query()->findOrFail($run->project_id)->project_identifier;

        // Round 1: one slot reports a blocking finding, the other nothing to fix.
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finding = Finding::query()->where('run_id', $run->id)->sole();
        $firstClear = ReviewResult::query()->where('run_id', $run->id)->where('round_number', 1)
            ->where('result_status', 'nothing_to_fix')->sole();
        $firstCheckpoint = (string) $run->fresh()?->checkpoint_tree_sha;
        self::assertSame(1, CriterionCoverage::query()->where('run_id', $run->id)->where('round_number', 1)
            ->where('slot_id', $this->reviewSlotIds[1])->count());

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        self::assertNotSame($firstCheckpoint, (string) $run->checkpoint_tree_sha);

        // The earlier result is not a terminal outcome of the new round, so the slot runs again.
        $store = $this->app->make(ReviewResultStore::class);
        self::assertNull($store->terminalOutcome($run, 2, $this->reviewSlotIds[1]));
        self::assertNotNull($store->terminalOutcome($run, 1, $this->reviewSlotIds[1]));

        $adapter = $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        $ticketContract = TicketApproval::query()->findOrFail($run->ticket_approval_id)->ticket_contract_sha256;
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $ticketContract);
        self::assertCount(count($this->reviewSlotIds), $adapter->renderedPrompts);
        foreach ($adapter->renderedPrompts as $prompt) {
            self::assertStringContainsString($ticketContract, $prompt);
            self::assertStringContainsString('Frühere gebundene Findings, je einmal einzustufen:', $prompt);
            self::assertStringContainsString($finding->id, $prompt);
        }

        // Review readiness of the new checkpoint required a result from every slot.
        $second = ReviewResult::query()->where('run_id', $run->id)->where('round_number', 2)->get();
        self::assertEqualsCanonicalizing($this->reviewSlotIds, $second->pluck('slot_id')->all());
        self::assertSame([(string) $run->fresh()?->checkpoint_tree_sha], $second->pluck('checkpoint_tree_sha')->unique()->all());
        self::assertSame($firstCheckpoint, $firstClear->checkpoint_tree_sha, 'The old result was rewritten.');

        // Coverage is recorded per round; the first round is never counted twice.
        self::assertSame(1, CriterionCoverage::query()->where('run_id', $run->id)->where('round_number', 1)
            ->where('slot_id', $this->reviewSlotIds[1])->count());
        self::assertSame(1, CriterionCoverage::query()->where('run_id', $run->id)->where('round_number', 2)
            ->where('slot_id', $this->reviewSlotIds[1])->count());

        // The resolution binds evidence of the new checkpoint, never the old result.
        $disposition = FindingDisposition::query()->where('finding_id', $finding->id)->sole();
        self::assertNotSame($firstClear->id, $disposition->evidence_review_result_id);
        self::assertSame(
            2,
            ReviewResult::query()->whereKey($disposition->evidence_review_result_id)->value('round_number'),
        );
    }

    /** TC-07: each presented finding gets exactly one immutable entry per slot and round. */
    public function test_every_status_entry_is_unique_per_slot_and_round_and_immutable(): void
    {
        $prepared = $this->preparedReviewRun('AI6-025-TC07');
        $run = $prepared['run'];
        $identifier = (string) Project::query()->findOrFail($run->project_id)->project_identifier;

        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::FINDINGS]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        $finding = Finding::query()->where('run_id', $run->id)->sole();

        // Round 2 keeps the finding open, round 3 confirms it.
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeFix($run, 1)->state);
        $run = $this->completeCheckRound($run, $identifier, 2);
        $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 2)->state);

        $secondFix = $this->executeFix($run, 2);
        self::assertSame(ExecutionJobState::SUCCEEDED, $secondFix->state, (string) $secondFix->failure_code);
        $run = $this->completeCheckRound($run, $identifier, 3);
        $this->reviewAdapter([]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 3)->state);

        foreach ([2, 3] as $round) {
            $entries = FindingStatus::query()->where('finding_id', $finding->id)
                ->where('source_role', 'quality_review')->where('round_number', $round)->get();
            self::assertCount(2, $entries, 'Round '.$round.' is not covered exactly once per slot.');
            self::assertEqualsCanonicalizing($this->reviewSlotIds, $entries->pluck('slot_id')->all());
        }
        self::assertSame(
            ['not_fixed', 'fixed'],
            [$this->statusOf($finding->id, 2), $this->statusOf($finding->id, 3)],
        );

        $existing = FindingStatus::query()->where('finding_id', $finding->id)
            ->where('source_role', 'quality_review')->where('round_number', 3)->firstOrFail();

        // A second entry for the same finding, slot and round is refused.
        try {
            FindingStatus::query()->create([
                ...$existing->only([
                    'run_id', 'finding_id', 'review_result_id', 'source_role', 'round_number', 'slot_id',
                    'status', 'evidence', 'checkpoint_tree_sha', 'source_provider_profile', 'source_model',
                    'source_effort', 'source_prompt_profile',
                ]),
                'id' => (string) Str::uuid(),
            ]);
            self::fail('A duplicate finding status was accepted.');
        } catch (QueryException) {
        }

        // The entry itself is immutable in the database contract.
        try {
            $existing->forceFill(['evidence' => 'Nachtraeglich veraendert.'])->save();
            self::fail('A finding status was updated.');
        } catch (QueryException) {
        }
        try {
            FindingStatus::query()->whereKey($existing->id)->delete();
            self::fail('A finding status was deleted.');
        } catch (QueryException) {
        }
        self::assertSame('fixed', FindingStatus::query()->findOrFail($existing->id)->status->value);
    }

    private function statusOf(string $findingId, int $round): string
    {
        return FindingStatus::query()->where('finding_id', $findingId)->where('round_number', $round)
            ->where('slot_id', $this->reviewSlotIds[0])->firstOrFail()->status->value;
    }
}
