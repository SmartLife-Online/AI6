<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RunCheckpoint extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['generation' => 'integer', 'evidence_epoch' => 'integer', 'is_current' => 'boolean'];
    }
}
