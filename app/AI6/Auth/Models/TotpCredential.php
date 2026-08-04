<?php

namespace App\AI6\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $encrypted_secret
 * @property int|null $last_used_timestep
 * @property Carbon|null $confirmed_at
 */
final class TotpCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'encrypted_secret',
        'last_used_timestep',
        'confirmed_at',
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
            'last_used_timestep' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
