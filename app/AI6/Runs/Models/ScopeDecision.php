<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable, idempotent decision on one exact additional scope path.
 *
 * @property string $id
 * @property string $run_id
 * @property string $path
 * @property string $outcome
 * @property string|null $human_request_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ScopeDecision extends Model
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
}
