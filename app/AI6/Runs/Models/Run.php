<?php

namespace App\AI6\Runs\Models;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property string $ticket_approval_id
 * @property string $status_operation_id
 * @property string|null $pending_status_operation_id
 * @property string|null $confirmed_branch_publication_oid
 * @property RunState $state
 * @property RunPhase $phase
 * @property WaitReason|null $wait_reason
 * @property int $version
 * @property string $claim_parent_control_sha
 * @property string $initial_run_base_sha
 * @property string $run_base_sha
 * @property string|null $run_branch
 * @property string|null $worktree_path
 * @property string|null $checkpoint_commit_sha
 * @property string|null $checkpoint_tree_sha
 * @property string|null $checkpoint_diff_hash
 * @property string|null $candidate_tree_sha
 * @property string|null $candidate_diff_hash
 * @property string|null $candidate_base_sha
 * @property string|null $candidate_checkpoint_commit_sha
 * @property string|null $candidate_ticket_contract_sha256
 * @property string|null $candidate_approval_snapshot_hash
 * @property int|null $candidate_evidence_epoch
 * @property string|null $candidate_scope_hash
 * @property string|null $candidate_config_hash
 * @property string|null $candidate_prompt_hash
 * @property string|null $candidate_security_policy_hash
 * @property Carbon|null $candidate_bound_at
 * @property Carbon|null $candidate_invalidated_at
 * @property RunType $run_type
 * @property string|null $review_subject_reference
 * @property string|null $review_subject_kind
 * @property string|null $review_subject_base_sha
 * @property string|null $review_subject_source_sha
 * @property string|null $review_workspace_hash
 * @property ReviewOnlyCompletionMode|null $completion_mode
 * @property array<string, mixed>|null $config_snapshot
 * @property string $config_hash
 * @property array<string, mixed>|null $scope_snapshot
 * @property string $scope_hash
 * @property list<string>|null $effective_scope_snapshot
 * @property string|null $effective_scope_hash
 * @property int $added_scope_paths_count
 * @property string|null $ticket_blob_sha
 * @property string|null $ticket_contract_sha256
 * @property list<string>|null $actual_changed_paths_snapshot
 * @property string|null $actual_changed_paths_hash
 * @property int $evidence_epoch
 * @property int|null $checkpoint_evidence_epoch
 * @property string|null $review_readiness_state
 * @property array<int, mixed>|null $review_blockers
 * @property Carbon|null $review_readiness_assessed_at
 * @property array<string, mixed>|null $prompt_snapshot
 * @property string $prompt_hash
 * @property array<string, mixed>|null $instruction_snapshot
 * @property string $instruction_hash
 * @property array<string, mixed>|null $runtime_profile_snapshot
 * @property string $runtime_profile_hash
 * @property array<string, mixed>|null $agent_profile_snapshot
 * @property string $agent_profile_hash
 * @property string $security_policy_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Run extends Model
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

    /** @return HasMany<RunAgent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(RunAgent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => RunState::class,
            'run_type' => RunType::class,
            'completion_mode' => ReviewOnlyCompletionMode::class,
            'phase' => RunPhase::class,
            'wait_reason' => WaitReason::class,
            'config_snapshot' => 'array',
            'scope_snapshot' => 'array',
            'prompt_snapshot' => 'array',
            'instruction_snapshot' => 'array',
            'runtime_profile_snapshot' => 'array',
            'agent_profile_snapshot' => 'array',
            'effective_scope_snapshot' => 'array',
            'actual_changed_paths_snapshot' => 'array',
            'evidence_epoch' => 'integer',
            'checkpoint_evidence_epoch' => 'integer',
            'candidate_evidence_epoch' => 'integer',
            'candidate_bound_at' => 'immutable_datetime',
            'candidate_invalidated_at' => 'immutable_datetime',
            'review_blockers' => 'array',
            'review_readiness_assessed_at' => 'immutable_datetime',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
