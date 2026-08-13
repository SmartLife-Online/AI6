<?php

namespace App\AI6\Projects\Models;

use App\AI6\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property string $approval_id
 * @property int $draft_id
 * @property string $control_commit
 * @property string $blob_sha
 * @property string $config_hash
 * @property array<string, mixed> $normalized_config
 * @property int $control_generation
 * @property int $approved_by
 * @property Carbon $approved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ProjectConfigSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectConfigDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProjectConfigDraft::class, 'draft_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'control_generation' => 'integer',
            'normalized_config' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
