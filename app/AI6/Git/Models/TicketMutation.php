<?php

namespace App\AI6\Git\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status_operation_id
 * @property string $relative_path
 * @property string $expected_ticket_blob_sha
 * @property string $base_content_sha256
 * @property string $base_content
 * @property string $target_content
 * @property string $source_status
 * @property string $target_status
 * @property string|null $source_contract_sha256
 * @property string $target_contract_sha256
 * @property string $expected_target_blob_sha
 * @property string $expected_target_tree_oid
 * @property string|null $prepared_commit_oid
 * @property int|null $prepared_attempt_token
 * @property int $expected_control_binding_version
 * @property string $audit_reason
 * @property list<array<string, int|string>> $audit_redaction_matches
 * @property bool $external_completion_confirmed
 * @property int $commit_timestamp
 */
final class TicketMutation extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'status_operation_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<ControlOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ControlOperation::class, 'status_operation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audit_redaction_matches' => 'array',
            'external_completion_confirmed' => 'boolean',
            'expected_control_binding_version' => 'integer',
            'prepared_attempt_token' => 'integer',
            'commit_timestamp' => 'integer',
        ];
    }
}
