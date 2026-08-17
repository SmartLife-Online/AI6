<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $run_id
 * @property string $slot_id
 * @property string $role
 * @property string $provider_profile
 * @property string $model
 * @property string $effort
 * @property string $prompt_profile
 * @property string|null $session_id
 */
final class RunAgent extends Model
{
    /** @var list<string> */
    protected $guarded = [];
}
