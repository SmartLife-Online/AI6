<?php

namespace App\AI6\Runs\Models;

use App\AI6\Runs\GateKind;
use App\AI6\Runs\GateState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property string $gate_id
 * @property GateKind $kind
 * @property GateState $state
 * @property bool $blocks_candidate
 * @property bool $blocks_final_commit
 * @property bool $blocks_push
 * @property string $ticket_contract_sha256
 * @property string|null $evidence_reference
 * @property string|null $evidence_ticket_contract_sha256
 * @property string|null $checkpoint_commit_sha
 * @property string|null $evidence_candidate_tree_sha
 * @property string|null $evidence_candidate_diff_hash
 * @property int|null $evidence_expected_run_version
 * @property string|null $evidence_source
 * @property Carbon|null $evidence_observed_at
 * @property string|null $evidence_digest
 * @property int|null $authorized_by
 * @property Carbon|null $authorized_at
 * @property Carbon|null $invalidated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RunGate extends Model
{
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
            'kind' => GateKind::class,
            'state' => GateState::class,
            'authorized_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'blocks_candidate' => 'boolean',
            'blocks_final_commit' => 'boolean',
            'blocks_push' => 'boolean',
            'evidence_expected_run_version' => 'integer',
            'evidence_observed_at' => 'immutable_datetime',
        ];
    }
}
