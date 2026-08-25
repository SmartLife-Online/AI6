<?php

namespace App\AI6\HumanLoop\Models;

use App\AI6\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $human_request_id
 * @property int $user_id
 * @property string $actor_role
 * @property bool $step_up_verified
 * @property string|null $step_up_proof_hash
 * @property string $chosen_effect
 * @property string|null $chosen_option_key
 * @property int $expected_run_version
 * @property string $wait_reason
 * @property string $bound_step_key
 * @property string $reason
 * @property string $idempotency_key
 * @property string|null $status_operation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Intervention extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step_up_verified' => 'boolean',
            'expected_run_version' => 'integer',
        ];
    }

    /** @return BelongsTo<HumanRequest, $this> */
    public function humanRequest(): BelongsTo
    {
        return $this->belongsTo(HumanRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
