<?php

namespace App\AI6\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $credential_public_key
 * @property int $signature_counter
 * @property string|null $label
 */
final class PasskeyCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'credential_id',
        'credential_public_key',
        'signature_counter',
        'label',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signature_counter' => 'integer'];
    }
}
