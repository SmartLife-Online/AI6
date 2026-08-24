<?php

namespace App\AI6\Reviews\Models;

use App\AI6\Reviews\FindingReviewStatus;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only assessment of an earlier finding by one reviewer in one round.
 *
 * @property string $id
 * @property string $run_id
 * @property string $finding_id
 * @property string|null $review_result_id
 * @property string $source_role
 * @property int $round_number
 * @property string $slot_id
 * @property FindingReviewStatus $status
 * @property string $evidence
 * @property string $checkpoint_tree_sha
 * @property string $source_provider_profile
 * @property string $source_model
 * @property string $source_effort
 * @property string $source_prompt_profile
 */
final class FindingStatus extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /** @return BelongsTo<ReviewResult, $this> */
    public function reviewResult(): BelongsTo
    {
        return $this->belongsTo(ReviewResult::class);
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'status' => FindingReviewStatus::class,
        ];
    }
}
