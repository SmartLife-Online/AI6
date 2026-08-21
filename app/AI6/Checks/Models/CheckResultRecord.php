<?php

namespace App\AI6\Checks\Models;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property int $evidence_epoch
 * @property CheckPhase $phase
 * @property string $profile
 * @property CheckResultState $state
 * @property string|null $reason
 * @property int|null $exit_code
 * @property int $duration_ms
 * @property string $redacted_output
 * @property string $tree_sha
 * @property string $result_tree_sha
 * @property bool $declared_side_effects
 * @property bool $declared_network
 * @property bool $declared_mutates
 * @property string $result_key
 * @property Carbon|null $superseded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CheckResultRecord extends Model
{
    protected $table = 'check_results';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phase' => CheckPhase::class,
            'evidence_epoch' => 'integer',
            'state' => CheckResultState::class,
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'declared_side_effects' => 'boolean',
            'declared_network' => 'boolean',
            'declared_mutates' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }
}
