<?php

namespace App\AI6\Reviews\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $run_id
 * @property string $review_result_id
 * @property int $round_number
 * @property string $slot_id
 * @property string $title
 * @property string $recommendation
 * @property string $reason
 */
final class InstructionRecommendation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];
}
