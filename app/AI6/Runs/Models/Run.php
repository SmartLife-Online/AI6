<?php

namespace App\AI6\Runs\Models;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property string $ticket_approval_id
 * @property string $status_operation_id
 * @property RunState $state
 * @property RunPhase $phase
 * @property WaitReason|null $wait_reason
 * @property int $version
 * @property string $claim_parent_control_sha
 * @property string $initial_run_base_sha
 * @property string $run_base_sha
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Run extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => RunState::class,
            'phase' => RunPhase::class,
            'wait_reason' => WaitReason::class,
            'config_snapshot' => 'array',
            'scope_snapshot' => 'array',
            'prompt_snapshot' => 'array',
            'instruction_snapshot' => 'array',
            'runtime_profile_snapshot' => 'array',
            'agent_profile_snapshot' => 'array',
            'version' => 'integer',
        ];
    }
}
