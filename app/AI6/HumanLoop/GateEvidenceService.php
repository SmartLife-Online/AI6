<?php

namespace App\AI6\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\Git\PublishCandidate;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Resolve candidate gate evidence on the generic intervention route. */
final readonly class GateEvidenceService
{
    public function __construct(private RunOrchestrator $runs, private ProjectPolicy $policy) {}

    public function authorize(
        HumanRequest $request,
        User $actor,
        int $runVersion,
        string $ticketContract,
        string $checkpoint,
        string $scope,
        string $agentSlot,
        string $requestedEffect,
        InterventionAuthorization $authorization,
        ?string $evidenceSource = null,
        ?string $evidenceObservedAt = null,
        ?string $evidenceDigest = null,
    ): Intervention {
        try {
            return DB::transaction(function () use ($request, $actor, $runVersion, $ticketContract, $checkpoint, $scope, $agentSlot, $requestedEffect, $authorization, $evidenceSource, $evidenceObservedAt, $evidenceDigest): Intervention {
                DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
                $fresh = HumanRequest::query()->findOrFail($request->id);
                $binding = GateEvidenceHumanRequestBinding::binding($fresh);
                $run = Run::query()->findOrFail($fresh->run_id);
                $project = $run->project()->firstOrFail();
                $membership = ProjectMembership::query()->where('project_id', $project->id)
                    ->where('user_id', $actor->id)->first();
                if ($binding === null || ! $membership instanceof ProjectMembership
                    || ! $this->policy->decide(ProjectAction::AUTHORIZE_GATE_EVIDENCE, $actor, $project)) {
                    throw new HumanRequestRejected('unauthorized', 'The actor may not authorize this gate evidence.');
                }
                if ($fresh->resolution_state !== HumanRequestResolutionState::OPEN) {
                    throw new HumanRequestRejected('request_already_resolved', 'The human request is already resolved.');
                }
                foreach ([
                    'stale_run_version' => [(string) $runVersion, (string) $fresh->bound_run_version],
                    'stale_ticket_contract' => [$ticketContract, $fresh->bound_ticket_contract],
                    'stale_checkpoint' => [$checkpoint, $fresh->bound_checkpoint],
                    'stale_scope' => [$scope, $fresh->bound_scope],
                    'stale_agent_slot' => [$agentSlot, $fresh->bound_agent_slot],
                    'stale_requested_effect' => [$requestedEffect, $fresh->bound_requested_effect],
                ] as $reason => [$provided, $expected]) {
                    if (! hash_equals($expected, $provided)) {
                        throw new HumanRequestRejected($reason, 'The gate answer binding is stale.');
                    }
                }
                if ($run->version !== $fresh->bound_run_version || $run->state !== RunState::WAITING
                    || $run->wait_reason !== WaitReason::MANUAL_GATE) {
                    throw new HumanRequestRejected('stale_run_version', 'The run moved after the gate request was opened.');
                }

                $interventionId = (string) Str::uuid();
                try {
                    $observedAt = is_string($evidenceObservedAt) && $evidenceObservedAt !== ''
                        ? CarbonImmutable::parse($evidenceObservedAt)
                        : null;
                } catch (Throwable) {
                    throw new HumanRequestRejected('external_evidence_incomplete', 'The external evidence timestamp is invalid.');
                }
                $digest = is_string($evidenceDigest) && $evidenceDigest !== ''
                    ? preg_replace('/\Asha256:/', '', strtolower($evidenceDigest))
                    : null;
                $resumed = $this->runs->resumeWait($run, $run->version, $fresh->bound_step_key, WaitReason::MANUAL_GATE);
                $this->runs->authorizeCandidateGateEvidence(
                    $resumed,
                    $binding['gate_id'],
                    $actor->id,
                    'intervention:'.$interventionId,
                    new PublishCandidate($binding['tree_oid'], $binding['diff_hash'], $resumed->run_base_sha),
                    $resumed->version + 1,
                    $evidenceSource,
                    $observedAt,
                    $digest,
                );
                $intervention = Intervention::query()->create([
                    'id' => $interventionId,
                    'human_request_id' => $fresh->id,
                    'user_id' => $actor->id,
                    'actor_role' => $membership->role->value,
                    'step_up_verified' => true,
                    'step_up_proof_hash' => $authorization->proofHash,
                    'chosen_effect' => GateEvidenceHumanRequestBinding::EFFECT,
                    'chosen_option_key' => GateEvidenceHumanRequestBinding::EFFECT,
                    'expected_run_version' => $fresh->bound_run_version,
                    'wait_reason' => WaitReason::MANUAL_GATE->value,
                    'bound_step_key' => $fresh->bound_step_key,
                    'reason' => 'Gebundene Gate-Evidenz autorisiert.',
                    'idempotency_key' => hash('sha256', $fresh->id.':'.GateEvidenceHumanRequestBinding::EFFECT.':'.$fresh->bound_run_version),
                ]);
                $fresh->forceFill(['resolution_state' => HumanRequestResolutionState::ANSWERED, 'resolved_at' => now()])->save();

                return $intervention;
            });
        } catch (UniqueConstraintViolationException) {
            throw new HumanRequestRejected('request_already_resolved', 'The gate request already has an intervention.');
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected(
                $conflict->reason === 'gate_external_evidence_incomplete' ? 'external_evidence_incomplete' : $conflict->reason,
                $conflict->getMessage(),
            );
        }
    }
}
