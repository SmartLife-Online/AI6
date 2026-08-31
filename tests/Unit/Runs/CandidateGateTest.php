<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\PublishCandidate;
use App\AI6\Reviews\FindingCategory;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\CandidateGate;
use App\AI6\Runs\GateKind;
use App\AI6\Runs\GateState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\ReviewOnlyCompletionPredicate;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class CandidateGateTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    public function test_each_candidate_gate_violation_blocks_individually_and_the_shared_review_evidence_is_reused(): void
    {
        $run = $this->reviewedRun('AI6-027-TC06');
        $candidate = new PublishCandidate(str_repeat('4', 64), str_repeat('5', 64), $run->run_base_sha);
        $gate = $this->app->make(CandidateGate::class);
        self::assertTrue($gate->decide($run, $candidate)->ready());

        $firstResultId = DB::table('review_results')->where('run_id', $run->id)->orderBy('slot_id')->value('id');
        if (! is_string($firstResultId)) {
            self::fail('The first review result is unavailable.');
        }
        $firstResult = ReviewResult::query()->findOrFail($firstResultId);
        $incomplete = $firstResult->replicate();
        $incomplete->id = (string) Str::uuid();
        $incomplete->round_number = 2;
        $incomplete->save();
        self::assertContains('criterion_coverage_incomplete:'.$this->approvalSlotId($run, $incomplete->slot_id), $gate->decide($run, $candidate)->blockers);
        self::assertContains(
            'criterion_coverage_incomplete:'.$this->approvalSlotId($run, $incomplete->slot_id),
            $this->app->make(ReviewOnlyCompletionPredicate::class)->decide($run)->blockers,
        );
        $coverage = CriterionCoverage::query()->where('review_result_id', $firstResult->id)->firstOrFail()->replicate();
        $coverage->id = (string) Str::uuid();
        $coverage->review_result_id = $incomplete->id;
        $coverage->round_number = 2;
        $coverage->save();
        foreach (ReviewResult::query()->where('run_id', $run->id)->where('round_number', 1)->where('id', '<>', $firstResult->id)->get() as $result) {
            $copy = $result->replicate();
            $copy->id = (string) Str::uuid();
            $copy->round_number = 2;
            $copy->save();
            foreach (CriterionCoverage::query()->where('review_result_id', $result->id)->get() as $criterion) {
                $criterionCopy = $criterion->replicate();
                $criterionCopy->id = (string) Str::uuid();
                $criterionCopy->review_result_id = $copy->id;
                $criterionCopy->round_number = 2;
                $criterionCopy->save();
            }
        }
        self::assertTrue($gate->decide($run, $candidate)->ready());

        $foreignTree = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run, $run->version, str_repeat('6', 64), str_repeat('7', 64), str_repeat('8', 64),
        );
        self::assertContains('review_round_missing', $gate->decide($foreignTree, $candidate)->blockers);
        $run = $this->app->make(RunOrchestrator::class)->applyScopeDecision(
            $foreignTree, 'app/Stale.php', true, null, 12,
            $this->app->make(CanonicalJson::class), 'auto_allow',
        );
        $staleBlockers = $gate->decide($run, $candidate)->blockers;
        self::assertTrue(
            collect($staleBlockers)->contains(static fn (string $blocker): bool => str_contains($blocker, '_check_missing:')),
            json_encode($staleBlockers, JSON_THROW_ON_ERROR),
        );

        RunGate::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'gate_id' => 'MG-01',
            'kind' => GateKind::MANUAL, 'state' => GateState::OPEN,
            'blocks_candidate' => true, 'blocks_final_commit' => true, 'blocks_push' => true,
            'ticket_contract_sha256' => DB::table('ticket_approvals')->where('id', $run->ticket_approval_id)
                ->value('ticket_contract_sha256'),
        ]);
        self::assertSame(['MG-01'], $gate->decide($run, $candidate)->openGates);

        Finding::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'review_result_id' => $incomplete->id,
            'round_number' => $incomplete->round_number, 'slot_id' => $incomplete->slot_id,
            'provider_profile' => $incomplete->provider_profile, 'model' => $incomplete->model,
            'effort' => $incomplete->effort, 'prompt_profile' => $incomplete->prompt_profile,
            'checkpoint_tree_sha' => $incomplete->checkpoint_tree_sha, 'diff_hash' => $incomplete->diff_hash,
            'local_id' => 'candidate-gate-blocker', 'severity' => FindingSeverity::HIGH,
            'original_disposition' => FindingOriginalDisposition::MUST_FIX,
            'category' => FindingCategory::CORRECTNESS, 'file' => 'app/Example.php', 'line' => 1,
            'title' => 'Blockierender Testbefund', 'evidence' => 'Gebundene Evidenz.',
            'expected_result' => 'Der Befund ist behoben.', 'criterion_refs' => ['AC-01'],
            'duplicate_group' => hash('sha256', 'candidate-gate-blocker'),
        ]);
        $blockedDecision = $gate->decide($run->fresh(), $candidate);
        self::assertContains('effective_finding_blocks_candidate', $blockedDecision->blockers);
    }

    private function reviewedRun(string $ticketId, AgentScenario $scenario = AgentScenario::SUCCESS): Run
    {
        $prepared = $this->preparedReviewRun($ticketId);
        $run = $prepared['run'];
        $this->reviewAdapter($scenario === AgentScenario::FINDINGS ? [$this->reviewSlotIds[0] => $scenario] : []);
        $review = $this->executeReview($run->fresh());
        self::assertSame('succeeded', $review->state->value, (string) $review->failure_code);

        $run = $run->fresh();
        config(['ai6.execution_mailboxes.checker_root' => $this->implementationTemp('candidate-gate-checker')]);
        $tree = $this->app->make(CheckRunner::class)->currentTreeBinding($run);
        foreach ([CheckPhase::BEFORE_REVIEW, CheckPhase::FINAL] as $phase) {
            foreach (($run->config_snapshot['values']['checks'][$phase->value] ?? []) as $profile) {
                CheckResultRecord::query()->create([
                    'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => $phase,
                    'evidence_epoch' => $run->evidence_epoch, 'profile' => $profile,
                    'state' => CheckResultState::SUCCEEDED, 'reason' => null, 'exit_code' => 0,
                    'duration_ms' => 1, 'redacted_output' => 'ok', 'tree_sha' => $tree, 'result_tree_sha' => $tree,
                    'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
                    'result_key' => CheckResult::key($run->id, $run->evidence_epoch, $phase, $profile, $tree),
                ]);
            }
        }

        return $run;
    }

    private function approvalSlotId(Run $run, string $slotId): string
    {
        return (string) DB::table('run_agents')->where('run_id', $run->id)->where('slot_id', $slotId)->value('approval_slot_id');
    }
}
