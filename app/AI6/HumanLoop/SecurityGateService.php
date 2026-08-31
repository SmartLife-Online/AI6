<?php

namespace App\AI6\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\FindingDispositionType;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Strongly authorized, candidate-bound exception for one critical security finding. */
final readonly class SecurityGateService
{
    public function __construct(private RunOrchestrator $runs) {}

    public function override(
        HumanRequest $request,
        User $actor,
        int $runVersion,
        string $ticketContract,
        string $checkpoint,
        string $scope,
        string $agentSlot,
        string $requestedEffect,
        InterventionAuthorization $authorization,
        string $reason,
    ): Intervention {
        $membership = ProjectMembership::query()->where('project_id', $request->project_id)
            ->where('user_id', $actor->id)->first();
        if (! $membership instanceof ProjectMembership || $membership->role !== ProjectRole::ADMIN) {
            $this->auditRejected($request, $actor, false, null, 'administrator_role_required');
            throw new HumanRequestRejected('administrator_role_required', 'The security override requires the administrator role.');
        }
        if (trim($reason) === '') {
            $this->auditRejected($request, $actor, true, $authorization->proofHash, 'reason_required');
            throw new HumanRequestRejected('reason_required', 'The security override requires a reason.');
        }

        try {
            return DB::transaction(function () use ($request, $actor, $membership, $runVersion, $ticketContract, $checkpoint, $scope, $agentSlot, $requestedEffect, $authorization, $reason): Intervention {
                DB::table('human_requests')->where('id', $request->id)->lockForUpdate()->first();
                $fresh = HumanRequest::query()->findOrFail($request->id);
                $binding = SecurityGateHumanRequestBinding::binding($fresh);
                $run = Run::query()->findOrFail($fresh->run_id);
                if ($binding === null || $fresh->resolution_state !== HumanRequestResolutionState::OPEN) {
                    throw new HumanRequestRejected('request_already_resolved', 'The security request is not open and bound.');
                }
                foreach ([
                    'stale_run_version' => [(string) $runVersion, (string) $fresh->bound_run_version],
                    'stale_ticket_contract' => [$ticketContract, $fresh->bound_ticket_contract],
                    'stale_checkpoint' => [$checkpoint, $fresh->bound_checkpoint],
                    'stale_scope' => [$scope, $fresh->bound_scope],
                    'stale_agent_slot' => [$agentSlot, $fresh->bound_agent_slot],
                    'stale_requested_effect' => [$requestedEffect, $fresh->bound_requested_effect],
                ] as $code => [$provided, $expected]) {
                    if (! hash_equals($expected, $provided)) {
                        throw new HumanRequestRejected($code, 'The security override binding is stale.');
                    }
                }
                if ($run->version !== $fresh->bound_run_version || $run->state !== RunState::WAITING
                    || $run->wait_reason !== WaitReason::SECURITY_GATE
                    || $binding['tree'] !== $run->candidate_tree_sha
                    || $binding['diff'] !== $run->candidate_diff_hash
                    || $binding['base'] !== $run->candidate_base_sha
                    || $binding['policy'] !== $run->security_policy_hash
                    || ! $this->matchesApprovedSecurityReviewer($run, $binding['profile_id'], $binding['instruction'])) {
                    throw new HumanRequestRejected('stale_run_version', 'The run moved after the security request was opened.');
                }
                $critical = Finding::query()->where('run_id', $run->id)
                    ->where('severity', FindingSeverity::CRITICAL->value)
                    ->whereHas('reviewResult', static function ($query) use ($binding): void {
                        $query->where('role', 'security_review')
                            ->where('candidate_tree_sha', $binding['tree'])
                            ->where('candidate_diff_hash', $binding['diff'])
                            ->where('candidate_base_sha', $binding['base'])
                            ->where('candidate_instruction_snapshot_hash', $binding['instruction'])
                            ->where('candidate_agent_profile_id', $binding['profile_id'])
                            ->where('candidate_security_policy_hash', $binding['policy']);
                    })->first();
                if (! $critical instanceof Finding) {
                    throw new HumanRequestRejected('critical_security_finding_missing', 'No current critical security finding can be overridden.');
                }

                $run = $this->runs->recordHumanFindingDisposition(
                    $run,
                    $critical,
                    $run->version,
                    FindingDispositionType::ACCEPTED_RISK,
                    trim($reason),
                    $actor,
                    $authorization->proofHash,
                    true,
                );

                $intervention = Intervention::query()->create([
                    'id' => (string) Str::uuid(),
                    'human_request_id' => $fresh->id,
                    'user_id' => $actor->id,
                    'actor_role' => $membership->role->value,
                    'step_up_verified' => true,
                    'step_up_proof_hash' => $authorization->proofHash,
                    'chosen_effect' => SecurityGateHumanRequestBinding::EFFECT,
                    'chosen_option_key' => SecurityGateHumanRequestBinding::EFFECT,
                    'expected_run_version' => $fresh->bound_run_version,
                    'wait_reason' => WaitReason::SECURITY_GATE->value,
                    'bound_step_key' => $fresh->bound_step_key,
                    'reason' => trim($reason),
                    'idempotency_key' => hash('sha256', $fresh->id.':'.SecurityGateHumanRequestBinding::EFFECT.':'.$fresh->bound_run_version),
                ]);
                $fresh->forceFill(['resolution_state' => HumanRequestResolutionState::ANSWERED, 'resolved_at' => now()])->save();
                $this->runs->resumeWait($run, $run->version, $fresh->bound_step_key, WaitReason::SECURITY_GATE);

                return $intervention;
            });
        } catch (UniqueConstraintViolationException) {
            throw new HumanRequestRejected('request_already_resolved', 'The security request already has an intervention.');
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected($conflict->reason, $conflict->getMessage());
        } catch (HumanRequestRejected $rejected) {
            $this->auditRejected($request, $actor, true, $authorization->proofHash, $rejected->reason);
            throw $rejected;
        }
    }

    /** Record a refused attempt in the existing request/intervention audit model. */
    public function auditRejected(
        HumanRequest $source,
        User $actor,
        bool $stepUpVerified,
        ?string $proofHash,
        string $reason,
    ): void {
        $membership = ProjectMembership::query()->where('project_id', $source->project_id)
            ->where('user_id', $actor->id)->first();
        if (! $membership instanceof ProjectMembership || $membership->role === ProjectRole::VIEWER) {
            return;
        }
        $key = hash('sha256', implode(':', [
            $source->id, $actor->id, 'security_override_rejected', $source->bound_run_version, $reason,
        ]));
        if (Intervention::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        DB::transaction(function () use ($source, $actor, $membership, $stepUpVerified, $proofHash, $reason, $key): void {
            $auditRequest = HumanRequest::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $source->run_id,
                'project_id' => $source->project_id,
                'kind' => 'security_override_rejected',
                'response_mode' => 'select',
                'title' => 'Abgelehnter Security-Override',
                'message' => 'Der Overrideversuch wurde ohne Runwirkung abgelehnt.',
                'why_needed' => $reason,
                'options' => [['key' => SecurityGateHumanRequestBinding::EFFECT, 'label' => 'Security-Override']],
                'recommended_option' => null,
                'affected_paths' => [],
                'criterion_refs' => [],
                'allowed_effects' => [SecurityGateHumanRequestBinding::EFFECT],
                'required_action' => $source->required_action,
                'bound_run_version' => $source->bound_run_version,
                'bound_ticket_contract' => $source->bound_ticket_contract,
                'bound_checkpoint' => $source->bound_checkpoint,
                'bound_scope' => $source->bound_scope,
                'bound_agent_slot' => $source->bound_agent_slot,
                'bound_requested_effect' => $source->bound_requested_effect,
                'bound_step_key' => $source->bound_step_key,
                'delivery_status' => HumanRequestDeliveryStatus::SENT,
                'delivery_attempts' => 0,
                'delivery_revision' => 1,
                'delivery_status_changed_at' => now(),
                'resolution_state' => HumanRequestResolutionState::ANSWERED,
                'resolved_at' => now(),
                'attention_user_id' => $source->attention_user_id,
            ]);
            Intervention::query()->create([
                'id' => (string) Str::uuid(),
                'human_request_id' => $auditRequest->id,
                'user_id' => $actor->id,
                'actor_role' => $membership->role->value,
                'step_up_verified' => $stepUpVerified,
                'step_up_proof_hash' => $stepUpVerified ? $proofHash : null,
                'chosen_effect' => 'security_override_rejected',
                'chosen_option_key' => SecurityGateHumanRequestBinding::EFFECT,
                'expected_run_version' => $source->bound_run_version,
                'wait_reason' => WaitReason::SECURITY_GATE->value,
                'bound_step_key' => $source->bound_step_key,
                'reason' => $reason,
                'idempotency_key' => $key,
            ]);
        });
    }

    private function matchesApprovedSecurityReviewer(Run $run, string $profileId, string $instructionHash): bool
    {
        $security = ($run->agent_profile_snapshot ?? [])['security_reviewer'] ?? null;
        if (! is_array($security) || ($security['profile_id'] ?? null) !== $profileId
            || ! is_string($provider = $security['provider_profile'] ?? null)) {
            return false;
        }
        $instruction = ($run->instruction_snapshot ?? [])[$provider] ?? null;

        return is_array($instruction)
            && is_string($current = $instruction['instruction_snapshot_hash'] ?? null)
            && hash_equals($current, $instructionHash);
    }
}
