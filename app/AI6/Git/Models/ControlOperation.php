<?php

namespace App\AI6\Git\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property int $actor_id
 * @property ControlOperationType $operation_type
 * @property int $schema_version
 * @property array<string, mixed> $authorization_snapshot
 * @property string $authorization_snapshot_jcs
 * @property string|null $expected_control_commit
 * @property string $operation_parameters_jcs
 * @property string $request_hash
 * @property ControlOperationPhase $phase
 * @property ControlOperationState $state
 * @property int $attempts
 * @property int|null $current_attempt_token
 * @property int|null $effect_attempt_token
 * @property string|null $lease_boot_id
 * @property string|null $launch_argument_hash
 * @property int|null $process_id
 * @property Carbon|null $process_started_at
 * @property string|null $key_fingerprint
 * @property string|null $finding_text
 * @property string|null $finding_hash
 * @property int|null $recovery_attempt_token
 * @property int|null $recovery_version
 * @property string|null $recovery_effect_hash
 * @property int $version
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $updated_at
 */
final class ControlOperation extends Model
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return HasOne<ControlOperationResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(ControlOperationResult::class);
    }

    /** @return HasOne<ControlOperationRecoveryDecision, $this> */
    public function recoveryDecision(): HasOne
    {
        $relation = $this->hasOne(ControlOperationRecoveryDecision::class);
        $relation->getQuery()->where('state', 'pending');

        return $relation;
    }

    /** @return HasMany<ControlOperationRecoveryDecision, $this> */
    public function recoveryDecisions(): HasMany
    {
        $relation = $this->hasMany(ControlOperationRecoveryDecision::class);
        $relation->getQuery()->orderBy('created_at');

        return $relation;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation_type' => ControlOperationType::class,
            'authorization_snapshot' => 'array',
            'phase' => ControlOperationPhase::class,
            'state' => ControlOperationState::class,
            'schema_version' => 'integer',
            'attempts' => 'integer',
            'current_attempt_token' => 'integer',
            'effect_attempt_token' => 'integer',
            'process_id' => 'integer',
            'process_started_at' => 'datetime',
            'recovery_attempt_token' => 'integer',
            'recovery_version' => 'integer',
            'version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
