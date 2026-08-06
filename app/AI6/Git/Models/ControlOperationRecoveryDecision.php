<?php

namespace App\AI6\Git\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Git\RecoveryDecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $control_operation_id
 * @property int $actor_id
 * @property RecoveryDecisionType $decision
 * @property int $expected_attempt_token
 * @property int $expected_operation_version
 * @property string $finding_hash
 * @property string|null $reason
 * @property string|null $bound_evidence
 * @property int|null $application_attempt_token
 * @property string|null $application_fingerprint
 * @property string $state
 */
final class ControlOperationRecoveryDecision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

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
        return [
            'decision' => RecoveryDecisionType::class,
            'expected_attempt_token' => 'integer',
            'expected_operation_version' => 'integer',
            'application_attempt_token' => 'integer',
            'applied_at' => 'datetime',
        ];
    }
}
