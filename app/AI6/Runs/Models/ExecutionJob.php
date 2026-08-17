<?php

namespace App\AI6\Runs\Models;

use App\AI6\Runs\ExecutionJobState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property string $step_type
 * @property int $step_number
 * @property string $idempotency_key
 * @property ExecutionJobState $state
 * @property string|null $lease_owner
 * @property Carbon|null $lease_expires_at
 * @property int $attempts
 * @property string|null $failure_code
 * @property string|null $intent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ExecutionJob extends Model
{
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
            'state' => ExecutionJobState::class,
            'lease_expires_at' => 'datetime',
            'attempts' => 'integer',
            'step_number' => 'integer',
        ];
    }
}
