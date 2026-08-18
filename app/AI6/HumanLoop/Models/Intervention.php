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
 * @property string $chosen_effect
 * @property string|null $chosen_option_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Intervention extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

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
