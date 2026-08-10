<?php

namespace App\AI6\Projects\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Models\ControlOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $control_operation_id
 * @property int $actor_id
 * @property string $old_branch
 * @property string $new_branch
 * @property string $old_control_oid
 * @property string $new_control_oid
 * @property Carbon $occurred_at
 */
final class ControlBranchAuditEntry extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ControlOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ControlOperation::class, 'control_operation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
