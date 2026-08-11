<?php

namespace App\AI6\Projects\Models;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $control_operation_id
 * @property string $relative_path
 * @property string $control_commit
 * @property string $blob_sha
 * @property int $control_generation
 * @property string|null $validation_profile
 * @property TicketDocumentState $document_state
 * @property string|null $ticket_contract_sha256
 * @property list<array{code: string, field: string, message: string}> $validation_errors
 * @property string $redacted_content
 * @property TicketReadModelRedactionState $redaction_state
 * @property list<array<string, int|string>> $redaction_matches
 * @property list<string> $source_blockers
 * @property bool $approval_editor_eligible
 * @property bool $editor_eligible
 * @property bool $approval_eligible
 * @property Carbon $generated_at
 */
final class TicketReadModel extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ControlOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ControlOperation::class, 'control_operation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'control_generation' => 'integer',
            'document_state' => TicketDocumentState::class,
            'redaction_state' => TicketReadModelRedactionState::class,
            'redaction_matches' => 'array',
            'validation_errors' => 'array',
            'source_blockers' => 'array',
            'approval_editor_eligible' => 'boolean',
            'editor_eligible' => 'boolean',
            'approval_eligible' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }
}
