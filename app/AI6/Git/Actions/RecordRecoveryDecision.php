<?php

namespace App\AI6\Git\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Git\RecoveryEvidenceReference;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final readonly class RecordRecoveryDecision
{
    public function __construct(
        private StepUpGuard $stepUp,
        private ProjectPolicy $projectPolicy,
        private Redactor $redactor,
    ) {}

    /** @param null|array{commit?: string|null, base_commit?: string|null, diff_sha256?: string|null} $boundEvidence */
    public function handle(
        Request $request,
        User $actor,
        ControlOperation $operation,
        RecoveryDecisionType $decision,
        int $expectedAttemptToken,
        int $expectedVersion,
        string $findingHash,
        ?string $reason,
        ?array $boundEvidence,
    ): ControlOperationRecoveryDecision {
        $project = $operation->project()->firstOrFail();
        if (! $this->projectPolicy->decideRecovery($actor, $project)) {
            throw new AuthorizationException;
        }

        $evidence = RecoveryEvidenceReference::bind(
            $boundEvidence,
            (int) $project->getKey(),
            $operation->id,
            $findingHash,
        );
        if ($decision === RecoveryDecisionType::ABANDON_OPERATION
            && (trim((string) $reason) === '' || $evidence === null)) {
            throw new ControlOperationConflict('Abandoning an operation requires a reason and bound evidence.');
        }
        if ($decision !== RecoveryDecisionType::ABANDON_OPERATION && $evidence !== null) {
            throw new ControlOperationConflict('Bound evidence is accepted only for operation abandonment.');
        }
        $context = new RedactionContext((string) $project->getKey(), $operation->id, 'control-operation-recovery-decision');
        try {
            $reason = $reason === null ? null : $this->redactor->redact($reason, $context)->text;
            $boundEvidence = $evidence === null ? null : $this->redactor->redact($evidence->toJson(), $context)->text;
        } catch (InvalidRedactionInputException) {
            throw new ControlOperationConflict('The recovery decision contains invalid text.');
        }

        return DB::transaction(function () use (
            $request,
            $operation,
            $actor,
            $decision,
            $expectedAttemptToken,
            $expectedVersion,
            $findingHash,
            $reason,
            $boundEvidence,
        ): ControlOperationRecoveryDecision {
            $updated = ControlOperation::query()
                ->whereKey($operation->id)
                ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                ->where('recovery_attempt_token', $expectedAttemptToken)
                ->where('recovery_version', $expectedVersion)
                ->where('current_attempt_token', $expectedAttemptToken)
                ->where('version', $expectedVersion)
                ->where('finding_hash', $findingHash)
                ->update(['version' => DB::raw('version')]);
            if ($updated !== 1) {
                throw new ControlOperationConflict('The recovery finding changed before the decision was recorded.');
            }

            // Freshness is checked and consumed immediately before the decision record is
            // created, per Task 16: it must not be burned by a finding-binding CAS failure
            // above, but a stale/already-used step-up proof must still be rejected ahead of
            // the pending-decision anti-replay guard below, so a resubmission with a stale
            // proof surfaces as "step-up required" rather than "decision already pending".
            $this->stepUp->consumeFresh($request, $actor, 'control_operation_recovery');

            if (ControlOperationRecoveryDecision::query()
                ->where('control_operation_id', $operation->id)
                ->where('state', 'pending')
                ->exists()) {
                throw new ControlOperationConflict('A recovery decision is already pending.');
            }

            $record = ControlOperationRecoveryDecision::query()->create([
                'id' => (string) Str::uuid(),
                'control_operation_id' => $operation->id,
                'actor_id' => $actor->getKey(),
                'decision' => $decision,
                'expected_attempt_token' => $expectedAttemptToken,
                'expected_operation_version' => $expectedVersion,
                'finding_hash' => $findingHash,
                'reason' => $reason,
                'bound_evidence' => $boundEvidence,
                'state' => 'pending',
            ]);
            Queue::connection('database')->push(new ExecuteControlOperation($operation->id));

            return $record;
        });
    }
}
