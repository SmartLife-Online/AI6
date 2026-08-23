<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentResult;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\InstructionRecommendation;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
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
    }

    private function redact(string $value, RedactionContext $context): string
    {
        return $this->redactor->redact($value, $context)->text;
    }
}
