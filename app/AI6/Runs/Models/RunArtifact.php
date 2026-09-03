<?php

namespace App\AI6\Runs\Models;

use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRetentionState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored run artifact or, after its retention deletion, its tombstone.
 *
 * A tombstone keeps redacted metadata, the central project- and run-bound HMAC
 * fingerprint with key id and version, size, expiry and deletion time. Its raw
 * reference and its unkeyed content digest are gone for good.
 *
 * @property string $id
 * @property string $run_id
 * @property RunArtifactKind $kind
 * @property array<string, mixed> $redacted_metadata
 * @property string|null $digest
 * @property int $size_bytes
 * @property int $sequence
 * @property string|null $storage_reference
 * @property Carbon $expires_at
 * @property RunArtifactRetentionState $retention_state
 * @property Carbon|null $deleted_at
 * @property int|null $fingerprint_version
 * @property string|null $fingerprint_key_id
 * @property string|null $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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

    public function isDeleted(): bool
    {
        return $this->retention_state === RunArtifactRetentionState::DELETED;
    }

    /**
     * Whether the retention of the raw bytes ended, whether or not the
     * retention run already removed them. From this moment on no output path
     * hands the bytes out any more.
     */
    public function isExpired(CarbonInterface $now): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RunArtifactKind::class,
            'retention_state' => RunArtifactRetentionState::class,
            'redacted_metadata' => 'array',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
            'size_bytes' => 'integer',
            'sequence' => 'integer',
            'fingerprint_version' => 'integer',
        ];
    }
}
