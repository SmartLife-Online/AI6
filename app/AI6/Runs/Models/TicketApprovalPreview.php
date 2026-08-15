<?php

namespace App\AI6\Runs\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $project_id
 * @property int $ticket_read_model_id
 * @property string $reviewed_ticket_blob_sha
 * @property string $reviewed_control_sha
 * @property string $ticket_contract_sha256
 * @property int $control_generation
 * @property array<string, mixed> $selection_snapshot
 * @property string $state
 * @property array<string, mixed>|null $approval_snapshot
 * @property string|null $approval_snapshot_hash
 * @property string|null $error_code
 * @property int $requested_by
 */
final class TicketApprovalPreview extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<TicketReadModel, $this> */
    public function readModel(): BelongsTo
    {
        return $this->belongsTo(TicketReadModel::class, 'ticket_read_model_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'selection_snapshot' => 'array',
            'approval_snapshot' => 'array',
            'control_generation' => 'integer',
        ];
    }
}
