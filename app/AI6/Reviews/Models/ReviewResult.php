<?php

namespace App\AI6\Reviews\Models;

use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $run_id
 * @property int $round_number
 * @property string $slot_id
 * @property int $attempt
 * @property string $session_id
 * @property ReviewInvocationOutcome $invocation_outcome
 * @property string|null $failure_code
 * @property string|null $result_status
 * @property string|null $workspace_tree_hash
 */
final class ReviewResult extends Model
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

    /** @return BelongsTo<RunArtifact, $this> */
    public function rawArtifact(): BelongsTo
    {
        return $this->belongsTo(RunArtifact::class, 'raw_artifact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'attempt' => 'integer',
            'invocation_outcome' => ReviewInvocationOutcome::class,
        ];
    }
}
