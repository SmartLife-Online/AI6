<?php

namespace App\AI6\Projects\Models;

use App\AI6\Git\Models\ControlOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $control_operation_id
 * @property string $control_commit
 * @property string|null $blob_sha
 * @property int $control_generation
 * @property string $state
 * @property string|null $config_hash
 * @property array<string, mixed>|null $normalized_config
 * @property list<array<string, mixed>> $validation_errors
 * @property list<array<string, mixed>> $redaction_matches
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ProjectConfigDraft extends Model
{
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

    protected function casts(): array
    {
        return [
            'control_generation' => 'integer',
            'normalized_config' => 'array',
            'validation_errors' => 'array',
            'redaction_matches' => 'array',
        ];
    }
}
