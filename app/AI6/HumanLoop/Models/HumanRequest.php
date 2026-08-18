<?php

namespace App\AI6\HumanLoop\Models;

use App\AI6\Auth\Models\User;
use App\AI6\HumanLoop\HumanRequestDeliveryStatus;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property int $project_id
 * @property string $kind
 * @property string $response_mode
 * @property string $title
 * @property string $message
 * @property string $why_needed
 * @property list<array{key: string, label: string}> $options
 * @property string|null $recommended_option
 * @property list<string> $affected_paths
 * @property list<string> $criterion_refs
 * @property list<string> $allowed_effects
 * @property string $required_action
 * @property int $bound_run_version
 * @property string $bound_ticket_contract
 * @property string $bound_checkpoint
 * @property string $bound_scope
 * @property string $bound_agent_slot
 * @property string $bound_requested_effect
 * @property string $bound_step_key
 * @property HumanRequestDeliveryStatus $delivery_status
 * @property int $delivery_attempts
 * @property int $delivery_revision
 * @property string|null $delivery_failure_key
 * @property Carbon|null $delivery_status_changed_at
 * @property HumanRequestResolutionState $resolution_state
 * @property int|null $attention_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $resolved_at
 */
final class HumanRequest extends Model
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attentionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attention_user_id');
    }

    /** @return HasOne<Intervention, $this> */
    public function intervention(): HasOne
    {
        return $this->hasOne(Intervention::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'affected_paths' => 'array',
            'criterion_refs' => 'array',
            'allowed_effects' => 'array',
            'bound_run_version' => 'integer',
            'delivery_status' => HumanRequestDeliveryStatus::class,
            'delivery_attempts' => 'integer',
            'delivery_revision' => 'integer',
            'delivery_status_changed_at' => 'datetime',
            'resolution_state' => HumanRequestResolutionState::class,
            'resolved_at' => 'datetime',
        ];
    }
}
