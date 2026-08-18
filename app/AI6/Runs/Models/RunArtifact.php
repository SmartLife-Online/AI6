<?php

namespace App\AI6\Runs\Models;

use App\AI6\Runs\RunArtifactKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property RunArtifactKind $kind
 * @property array<string, mixed> $redacted_metadata
 * @property string $digest
 * @property int $size_bytes
 * @property int $sequence
 * @property string $storage_reference
 * @property Carbon $expires_at
 */
final class RunArtifact extends Model
{
    protected $table = 'run_artifacts';

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
        return [
            'kind' => RunArtifactKind::class,
            'redacted_metadata' => 'array',
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
            'sequence' => 'integer',
        ];
    }
}
