<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property string $limit_name
 * @property string $consumption_key
 * @property int $quantity
 */
final class RunLimitConsumption extends Model
{
    public const UPDATED_AT = null;

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
        return ['quantity' => 'integer'];
    }
}
