<?php

namespace App\AI6\Reviews\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $run_id
 * @property string $review_result_id
 * @property int $round_number
 * @property string $slot_id
 * @property string $criterion_id
 * @property string $status
 * @property string $evidence
 */
final class CriterionCoverage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];
}
