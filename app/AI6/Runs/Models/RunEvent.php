<?php

namespace App\AI6\Runs\Models;

use App\AI6\Runs\RetentionCategory;
use App\AI6\Runs\RunLogRetentionBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * A redacted timeline event or, after its retention deletion, its tombstone:
 * the fixed marker plus the central project- and run-bound HMAC fingerprint of
 * the removed payload with key id and version, its size, the expiry that
 * applied and the deletion time. The message-derived idempotency key is gone
 * with the payload.
 *
 * @property int $id
 * @property string $run_id
 * @property string $event_type
 * @property string|null $event_key
 * @property string $redacted_payload
 * @property Carbon|null $retention_expires_at
 * @property Carbon|null $retention_deleted_at
 * @property int|null $retention_size_bytes
 * @property int|null $fingerprint_version
 * @property string|null $fingerprint_key_id
 * @property string|null $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RunEvent extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * Every event is bound at its creation: expiry from the trusted value of
     * its category at this moment, size and the central HMAC fingerprint of
     * its redacted payload, over the server's own persistence time. The
     * binding is written unconditionally — a caller-supplied expiry, size,
     * fingerprint, deletion or creation time never survives, so no caller can
     * extend the retention of a row or forge its tombstone provenance. The
     * guard refuses an unbound row.
     */
    protected static function booted(): void
    {
        self::creating(static function (RunEvent $event): void {
            $event->created_at = Date::now();
            $event->forceFill(app(RunLogRetentionBinding::class)->bind($event->run_id, RetentionCategory::RUN_LOGS, $event->redacted_payload, $event->created_at));
        });
    }

    public function isTombstone(): bool
    {
        return $this->retention_deleted_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retention_expires_at' => 'datetime',
            'retention_deleted_at' => 'datetime',
            'retention_size_bytes' => 'integer',
            'fingerprint_version' => 'integer',
        ];
    }
}
