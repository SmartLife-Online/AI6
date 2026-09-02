<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;

/**
 * The read-only readiness checks that run before any agent work is allowed.
 *
 * The order is fixed: approval binding, run base binding, workspace and
 * checkpoint readiness, process and sandbox preconditions, and finally the
 * prompt, instruction and runtime profile binding. The first violated check
 * names the failure; no check starts a process, a Git operation or an agent.
 */
final readonly class RunPreflight
{
    public function __construct(
        private AgentProfileRegistry $agentProfiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private ProcessPolicyRegistry $processPolicies,
    ) {}

    public function failureCode(Run $run): ?string
    {
        $approval = TicketApproval::query()->whereKey($run->ticket_approval_id)
            ->where('project_id', $run->project_id)->first();
        if (! $approval instanceof TicketApproval || $approval->saga_phase !== 'complete') {
            return 'approval_not_complete';
        }
        if (! $this->sha($approval->approved_control_sha)
            || (! hash_equals((string) $approval->approved_control_sha, $run->claim_parent_control_sha)
                && ! $this->confirmedFastForwardClaim($run, $approval))
            || ! $this->sha($approval->approved_ticket_blob_sha)) {
            return 'approval_binding_stale';
        }

        foreach (['run_base_sha', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash'] as $field) {
            if (! $this->sha($run->{$field})) {
                return 'preflight_binding_missing';
            }
        }
        $diverged = [];
        foreach (['config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash'] as $field) {
            if (! hash_equals((string) $approval->{$field}, (string) $run->{$field})) {
                $diverged[] = $field;
            }
        }
        // Scope and prompt may legitimately diverge from the approval once a
        // confirmed contract amendment binds the current run base. The config
        // binding is not in that set: an amendment changes exactly the ticket
        // file, and finalizeAmendedRun proves the effective project
        // configuration stayed byte-stable, so a config divergence is foreign
        // drift even behind a confirmed amendment. The instruction, runtime,
        // agent and security bindings never move without a new approval
        // (AC-11, AC-12).
        if ($diverged !== []
            && (array_diff($diverged, ['scope_hash', 'prompt_hash']) !== []
                || ! $this->confirmedAmendmentBindsRunBase($run))) {
            return 'snapshot_binding_changed';
        }

        if (! is_array($run->config_snapshot) || ! is_array($run->scope_snapshot)
            || ! is_array($run->prompt_snapshot) || ! is_array($run->instruction_snapshot)
            || ! is_array($run->runtime_profile_snapshot) || ! is_array($run->agent_profile_snapshot)) {
            return 'preflight_snapshot_missing';
        }
        if ($run->run_branch === null || $run->worktree_path === null
            || $run->checkpoint_commit_sha === null || $run->checkpoint_tree_sha === null
            || $run->checkpoint_diff_hash === null) {
            return 'workspace_checkpoint_missing';
        }

        return $this->processFailure() ?? $this->profileFailure($run);
    }

    /** Process policy and sandbox roots must be usable before a step may be prepared. */
    private function processFailure(): ?string
    {
        $inputRoot = config('ai6.execution_mailboxes.agent_root');
        $outputRoot = config('ai6.execution_mailboxes.agent_output_root');
        if (! is_string($inputRoot) || $inputRoot === ''
            || ! is_string($outputRoot) || $outputRoot === ''
            || $inputRoot === $outputRoot) {
            return 'sandbox_roots_not_isolated';
        }

        // The agent policy must actually cover the sandbox the mailbox writes into;
        // an empty environment allowlist or a foreign working root would let the
        // later process start decide what the preflight already promised.
        $policy = $this->processPolicies->get(ProcessPolicyName::AGENT);
        if ($policy->environmentAllowlist === [] || ! in_array($inputRoot, $policy->workingRoots, true)) {
            return 'process_policy_unavailable';
        }

        return null;
    }

    /** The bound prompt, instruction and runtime profiles must still be server-registered. */
    private function profileFailure(Run $run): ?string
    {
        $implementation = ($run->agent_profile_snapshot ?? [])['implementation'] ?? null;
        if (! is_array($implementation)
            || ! is_string($profileId = $implementation['profile_id'] ?? null) || $profileId === ''
            || ! is_string($runtimeProfileId = $implementation['runtime_profile_id'] ?? null) || $runtimeProfileId === ''
            || ! is_string($providerProfile = $implementation['provider_profile'] ?? null) || $providerProfile === '') {
            return 'agent_profile_binding_missing';
        }
        if (! $this->agentProfiles->has($profileId)) {
            return 'agent_profile_not_registered';
        }

        $boundRuntime = ($run->runtime_profile_snapshot ?? [])[$runtimeProfileId] ?? null;
        if (! is_array($boundRuntime) || ! is_string($boundHash = $boundRuntime['hash'] ?? null)) {
            return 'runtime_profile_binding_missing';
        }
        try {
            $registered = $this->runtimeProfiles->get($runtimeProfileId);
        } catch (ConfigurationException) {
            return 'runtime_profile_not_server_bound';
        }
        if (! hash_equals($registered->hash, $boundHash)) {
            return 'runtime_profile_not_server_bound';
        }

        $instructions = ($run->instruction_snapshot ?? [])[$providerProfile] ?? null;
        if (! is_array($instructions) || ! $this->sha($instructions['instruction_snapshot_hash'] ?? null)) {
            return 'instruction_binding_missing';
        }

        $prompt = (($run->prompt_snapshot ?? [])['rendered_prompts'] ?? [])['implementation'] ?? null;
        if (! is_string($prompt) || $prompt === '') {
            return 'prompt_binding_missing';
        }

        return null;
    }

    private function confirmedAmendmentBindsRunBase(Run $run): bool
    {
        return ControlOperation::query()
            ->where('project_id', $run->project_id)
            ->where('operation_type', ControlOperationType::CONTRACT_AMENDMENT->value)
            ->where('state', ControlOperationState::COMPLETED->value)
            ->where('target_control_oid', $run->run_base_sha)
            ->whereRaw("json_extract(operation_parameters_jcs, '\$.run_id') = ?", [$run->getKey()])
            ->exists();
    }

    private function confirmedFastForwardClaim(Run $run, TicketApproval $approval): bool
    {
        if (! $this->sha($run->claim_parent_control_sha) || ! $this->sha($run->initial_run_base_sha)) {
            return false;
        }

        return ControlOperation::query()
            ->whereKey($run->status_operation_id)
            ->where('project_id', $run->project_id)
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->where('state', ControlOperationState::COMPLETED->value)
            ->where('expected_control_commit', $run->claim_parent_control_sha)
            ->where('target_control_oid', $run->initial_run_base_sha)
            ->whereRaw("json_extract(operation_parameters_jcs, '\$.approval_id') = ?", [$approval->getKey()])
            ->exists();
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
