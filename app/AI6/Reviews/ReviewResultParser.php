<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentResult;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingStatus;
use App\AI6\Reviews\Models\InstructionRecommendation;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Str;

/** Normalizes one already schema-validated provider result at the persistence boundary. */
final readonly class ReviewResultParser
{
    public function __construct(private Redactor $redactor) {}

    public function persist(ReviewResult $review, Run $run, AgentResult $result, RedactionContext $context): void
    {
        foreach ($result->findings as $source) {
            $severity = FindingSeverity::tryFrom(strtolower($source->severity));
            $disposition = FindingOriginalDisposition::tryFrom(strtolower($source->disposition));
            $category = FindingCategory::tryFrom(strtolower($source->category));
            if (! $severity instanceof FindingSeverity) {
                throw new ReviewResultParseException('finding_severity_unknown');
            }
            if (! $disposition instanceof FindingOriginalDisposition) {
                throw new ReviewResultParseException('finding_disposition_unknown');
            }
            if (! $category instanceof FindingCategory) {
                throw new ReviewResultParseException('finding_category_unknown');
            }

            $file = $this->redact($source->file, $context);
            $title = $this->redact($source->title, $context);
            $evidence = $this->redact($source->evidence, $context);
            $expected = $this->redact($source->expectedResult, $context);
            $duplicateGroup = ExactFindingGroup::key(
                $severity,
                $disposition,
                $category,
                $file,
                $source->line,
                $title,
                $evidence,
                $expected,
                $source->criterionRefs,
            );

            Finding::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'review_result_id' => $review->id,
                'round_number' => $review->round_number,
                'slot_id' => $review->slot_id,
                'provider_profile' => $review->provider_profile,
                'model' => $review->model,
                'effort' => $review->effort,
                'prompt_profile' => $review->prompt_profile,
                'checkpoint_tree_sha' => $review->checkpoint_tree_sha,
                'diff_hash' => $review->diff_hash,
                'local_id' => $source->localId,
                'severity' => $severity,
                'original_disposition' => $disposition,
                'category' => $category->value,
                'file' => $file,
                'line' => $source->line,
                'title' => $title,
                'evidence' => $evidence,
                'expected_result' => $expected,
                'criterion_refs' => $source->criterionRefs,
                'duplicate_group' => $duplicateGroup,
            ]);
        }

        foreach ($result->criterionCoverage as $entry) {
            CriterionCoverage::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'review_result_id' => $review->id,
                'round_number' => $review->round_number,
                'slot_id' => $review->slot_id,
                'criterion_id' => $entry->criterionId,
                'status' => $entry->status,
                'evidence' => $this->redact($entry->evidence, $context),
            ]);
        }

        foreach ($result->instructionRecommendations as $entry) {
            InstructionRecommendation::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'review_result_id' => $review->id,
                'round_number' => $review->round_number,
                'slot_id' => $review->slot_id,
                'title' => $this->redact($entry->title, $context),
                'recommendation' => $this->redact($entry->recommendation, $context),
                'reason' => $this->redact($entry->reason, $context),
            ]);
        }

        foreach ($result->findingStatuses as $entry) {
            FindingStatus::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'finding_id' => $entry->findingId,
                'review_result_id' => $review->id,
                'source_role' => 'quality_review',
                'round_number' => $review->round_number,
                'slot_id' => $review->slot_id,
                'status' => $entry->status,
                'evidence' => $this->redact($entry->evidence, $context),
                'checkpoint_tree_sha' => $review->checkpoint_tree_sha,
                'source_provider_profile' => $review->provider_profile,
                'source_model' => $review->model,
                'source_effort' => $review->effort,
                'source_prompt_profile' => $review->prompt_profile,
            ]);
        }
    }

    /**
     * Persist the fix turn's own assessment of the findings it was given.
     *
     * The entry is evidence only: it binds the implementation slot as its source,
     * never a review result, and {@see EffectiveFindingState} does not read it. A
     * rejection therefore documents the agent's position for an authorized human
     * disposition instead of resolving anything (AC-07).
     */
    public function persistImplementationStatuses(
        Run $run,
        RunAgent $slot,
        int $round,
        AgentResult $result,
        RedactionContext $context,
    ): void {
        foreach ($result->findingStatuses as $entry) {
            // A redelivered fix turn repeats the same assessment for the same
            // coordinate. The entry is append-only and immutable, so the first
            // one stands and the retry adds nothing instead of colliding.
            FindingStatus::query()->firstOrCreate([
                'run_id' => $run->id,
                'finding_id' => $entry->findingId,
                'slot_id' => $slot->slot_id,
                'round_number' => $round,
            ], [
                'id' => (string) Str::uuid(),
                'review_result_id' => null,
                'source_role' => 'implementation',
                'status' => $entry->status,
                'evidence' => $this->redact($entry->evidence, $context),
                'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
                'source_provider_profile' => $slot->provider_profile,
                'source_model' => $slot->model,
                'source_effort' => $slot->effort,
                'source_prompt_profile' => $slot->prompt_profile,
            ]);
        }
    }

    private function redact(string $value, RedactionContext $context): string
    {
        return $this->redactor->redact($value, $context)->text;
    }
}
