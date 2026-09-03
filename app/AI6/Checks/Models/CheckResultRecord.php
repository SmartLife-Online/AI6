<?php

namespace App\AI6\Checks\Models;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RetentionCategory;
use App\AI6\Runs\RunLogRetentionBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * @property string $id
 * @property string $run_id
 * @property int $evidence_epoch
 * @property CheckPhase $phase
 * @property string $profile
 * @property CheckResultState $state
 * @property string|null $reason
 * @property int|null $exit_code
 * @property int $duration_ms
 * @property string $redacted_output
 * @property string $tree_sha
 * @property string $result_tree_sha
 * @property bool $declared_side_effects
 * @property bool $declared_network
 * @property bool $declared_mutates
 * @property string $result_key
 * @property Carbon|null $superseded_at
 * @property Carbon|null $retention_expires_at
 * @property Carbon|null $retention_deleted_at
 * @property int|null $retention_size_bytes
 * @property int|null $fingerprint_version
 * @property string|null $fingerprint_key_id
 * @property string|null $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CheckResultRecord extends Model
{
    protected $table = 'check_results';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * Every check result is bound at its creation: expiry from the trusted
     * value of the check-log category at this moment, size and the central
     * HMAC fingerprint of its redacted output, over the server's own
     * persistence time. The binding is written unconditionally — a
     * caller-supplied expiry, size, fingerprint, deletion or creation time
     * never survives, so no caller can extend the retention of a row or forge
     * its tombstone provenance. The guard refuses an unbound row.
     */
    protected static function booted(): void
    {
        self::creating(static function (CheckResultRecord $result): void {
            $result->created_at = Date::now();
            $result->forceFill(app(RunLogRetentionBinding::class)->bind($result->run_id, RetentionCategory::CHECK_LOGS, $result->redacted_output, $result->created_at));
        });
    }

    /** Whether the retention run replaced the output by its bound tombstone. */
    public function isTombstone(): bool
    {
        return $this->retention_deleted_at !== null;
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phase' => CheckPhase::class,
            'evidence_epoch' => 'integer',
            'state' => CheckResultState::class,
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'declared_side_effects' => 'boolean',
            'declared_network' => 'boolean',
            'declared_mutates' => 'boolean',
            'superseded_at' => 'datetime',
            'retention_expires_at' => 'datetime',
            'retention_deleted_at' => 'datetime',
            'retention_size_bytes' => 'integer',
            'fingerprint_version' => 'integer',
        ];
    }
}
