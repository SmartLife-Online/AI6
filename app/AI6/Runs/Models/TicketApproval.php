<?php

namespace App\AI6\Runs\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property string $status_operation_id
 * @property string $ticket_id
 * @property string $relative_path
 * @property string $reviewed_ticket_blob_sha
 * @property string $reviewed_control_sha
 * @property string|null $approved_ticket_blob_sha
 * @property string|null $approved_control_sha
 * @property string $ticket_contract_sha256
 * @property int $control_generation
 * @property string $snapshot_context_id
 * @property string $saga_phase
 * @property string|null $intended_commit_sha
 * @property array<string, mixed> $config_snapshot
 * @property string $config_hash
 * @property array<string, mixed> $scope_snapshot
 * @property string $scope_hash
 * @property array<string, mixed> $prompt_snapshot
 * @property string $prompt_hash
 * @property array<string, mixed> $instruction_snapshot
 * @property string $instruction_hash
 * @property array<string, mixed> $runtime_profile_snapshot
 * @property string $runtime_profile_hash
 * @property array<string, mixed> $agent_profile_snapshot
 * @property string $agent_profile_hash
 * @property string $security_policy_hash
 * @property array<string, int> $limits_snapshot
 * @property string $approval_snapshot_hash
 * @property string $queue_state
 * @property int|null $attention_user_id
 * @property string $push_mode
 * @property RunType $run_type
 * @property string|null $review_subject_reference
 * @property ReviewOnlyCompletionMode|null $completion_mode
 * @property int $version
 * @property int $approved_by
 * @property Carbon $approved_at
 */
final class TicketApproval extends Model
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

    /** @return BelongsTo<ControlOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(ControlOperation::class, 'status_operation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config_snapshot' => 'array',
            'scope_snapshot' => 'array',
            'prompt_snapshot' => 'array',
            'instruction_snapshot' => 'array',
            'runtime_profile_snapshot' => 'array',
            'agent_profile_snapshot' => 'array',
            'limits_snapshot' => 'array',
            'control_generation' => 'integer',
            'attention_user_id' => 'integer',
            'version' => 'integer',
            'approved_at' => 'immutable_datetime',
            'run_type' => RunType::class,
            'completion_mode' => ReviewOnlyCompletionMode::class,
        ];
    }
}
