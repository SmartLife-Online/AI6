<?php

namespace App\AI6\Reviews\Models;

use App\AI6\Reviews\FindingCategory;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $run_id
 * @property string $review_result_id
 * @property int $round_number
 * @property string $slot_id
 * @property string $provider_profile
 * @property string $model
 * @property string $effort
 * @property string $prompt_profile
 * @property string $checkpoint_tree_sha
 * @property string $diff_hash
 * @property string $local_id
 * @property FindingSeverity $severity
 * @property FindingOriginalDisposition $original_disposition
 * @property FindingCategory $category
 * @property string $file
 * @property int $line
 * @property string $title
 * @property string $evidence
 * @property string $expected_result
 * @property list<string> $criterion_refs
 * @property string $duplicate_group
 * @property-read Collection<int, FindingDisposition> $dispositions
 */
final class Finding extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return BelongsTo<ReviewResult, $this> */
    public function reviewResult(): BelongsTo
    {
        return $this->belongsTo(ReviewResult::class);
    }

    /** @return HasMany<FindingDisposition, $this> */
    public function dispositions(): HasMany
    {
        return $this->hasMany(FindingDisposition::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'line' => 'integer',
            'severity' => FindingSeverity::class,
            'original_disposition' => FindingOriginalDisposition::class,
            'category' => FindingCategory::class,
            'criterion_refs' => 'array',
        ];
    }
}
