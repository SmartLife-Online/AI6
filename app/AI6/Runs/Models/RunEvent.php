<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $run_id
 * @property string $event_type
 * @property string|null $event_key
 * @property string $redacted_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RunEvent extends Model
{
    /** @var list<string> */
    protected $guarded = [];
}
