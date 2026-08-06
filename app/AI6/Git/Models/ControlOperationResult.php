<?php

namespace App\AI6\Git\Models;

use App\AI6\Git\ControlOperationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $control_operation_id
 * @property ControlOperationOutcome $outcome
 * @property string $result_binding
 * @property string $safe_summary
 */
final class ControlOperationResult extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<ControlOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ControlOperation::class, 'control_operation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['outcome' => ControlOperationOutcome::class];
    }
}
