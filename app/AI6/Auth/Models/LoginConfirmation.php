<?php

namespace App\AI6\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $revision
 * @property string $code_digest
 * @property string $recipient_digest
 * @property string $session_digest
 * @property Carbon $expires_at
 * @property int $attempt_count
 * @property string $delivery_status
 * @property Carbon $delivery_status_changed_at
 * @property string|null $failure_key
 * @property Carbon|null $consumed_at
 * @property Carbon|null $invalidated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class LoginConfirmation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'revision',
        'code_digest',
        'recipient_digest',
        'session_digest',
        'expires_at',
        'attempt_count',
        'delivery_status',
        'delivery_status_changed_at',
        'failure_key',
        'consumed_at',
        'invalidated_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'attempt_count' => 'integer',
            'expires_at' => 'datetime',
            'delivery_status_changed_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
