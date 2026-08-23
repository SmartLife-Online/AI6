<?php

namespace App\AI6\Reviews\Models;

use App\AI6\Reviews\FindingDispositionSource;
use App\AI6\Reviews\FindingDispositionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $finding_id
 * @property FindingDispositionType $type
 * @property string $reason
 * @property FindingDispositionSource $decision_source
 * @property string|null $evidence_review_result_id
 * @property int|null $decided_by
 * @property string|null $decision_role
 * @property string|null $step_up_proof_hash
 * @property int $expected_run_version
 * @property string $request_hash
 * @property string $ticket_contract_sha256
 * @property string $config_hash
 * @property string $scope_hash
 * @property string $prompt_hash
 * @property string $instruction_hash
 * @property string $runtime_profile_hash
 * @property string $agent_profile_hash
 * @property string $security_policy_hash
 * @property string $checkpoint_tree_sha
 * @property string $diff_hash
 * @property string $reviewer_binding_hash
 */
final class FindingDisposition extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FindingDispositionType::class,
            'decision_source' => FindingDispositionSource::class,
            'expected_run_version' => 'integer',
        ];
    }
}
