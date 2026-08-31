<?php

namespace App\AI6\Reviews;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\HumanLoop\SecurityGateHumanRequestBinding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;

/** Read-side decision for a security result or a strongly authorized override. */
final class SecurityReviewEvidence
{
    public function __construct(private readonly SecurityReviewPrompt $prompts) {}

    public function validClear(Run $run): ?ReviewResult
    {
        if (! $this->candidateCurrent($run)) {
            return null;
        }

        $resultIds = ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'security_review')
            ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT->value)
            ->where('result_status', 'clear')
            ->orderByDesc('created_at')->pluck('id');
        foreach ($resultIds as $resultId) {
            $result = is_string($resultId) ? ReviewResult::query()->find($resultId) : null;
            if ($result instanceof ReviewResult && $this->matches($result, $run)) {
                return $result;
            }
        }

        return null;
    }

    public function validOverride(Run $run): ?Intervention
    {
        if (! $this->candidateCurrent($run)) {
            return null;
        }
        $interventionIds = Intervention::query()->where('chosen_effect', SecurityGateHumanRequestBinding::EFFECT)
            ->where('step_up_verified', true)->orderByDesc('created_at')->pluck('id');
        foreach ($interventionIds as $interventionId) {
            $intervention = is_string($interventionId) ? Intervention::query()->find($interventionId) : null;
            if (! $intervention instanceof Intervention) {
                continue;
            }
            $request = HumanRequest::query()->find($intervention->human_request_id);
            $binding = $request instanceof HumanRequest ? SecurityGateHumanRequestBinding::binding($request) : null;
            if ($request instanceof HumanRequest && $request->run_id === $run->id && is_array($binding)
                && $intervention->actor_role === 'admin'
                && $binding['tree'] === $run->candidate_tree_sha
                && $binding['diff'] === $run->candidate_diff_hash
                && $binding['base'] === $run->candidate_base_sha
                && $binding['policy'] === $run->security_policy_hash
                && $binding['instruction'] === $this->instructionHash($run, $binding['profile_id'])) {
                return $intervention;
            }
        }

        return null;
    }

    public function allowsContinuation(Run $run): bool
    {
        return $this->validClear($run) instanceof ReviewResult || $this->validOverride($run) instanceof Intervention;
    }

    private function matches(ReviewResult $result, Run $run): bool
    {
        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        $expected = [
            'candidate_tree_sha' => $run->candidate_tree_sha,
            'candidate_diff_hash' => $run->candidate_diff_hash,
            'candidate_base_sha' => $run->candidate_base_sha,
            'candidate_ticket_contract_sha256' => $run->candidate_ticket_contract_sha256,
            'candidate_scope_hash' => $run->candidate_scope_hash,
            'candidate_security_policy_hash' => $run->security_policy_hash,
            'approval_config_hash' => $run->config_hash,
            'approval_scope_hash' => $run->scope_hash,
            'approval_prompt_hash' => $run->prompt_hash,
            'approval_instruction_hash' => $run->instruction_hash,
            'approval_runtime_profile_hash' => $run->runtime_profile_hash,
            'approval_agent_profile_hash' => $run->agent_profile_hash,
            'approval_security_policy_hash' => $run->security_policy_hash,
            'approval_snapshot_hash' => $approval?->approval_snapshot_hash,
        ];
        foreach ($expected as $field => $value) {
            $actual = $result->getAttribute($field);
            if (! is_string($actual) || ! is_string($value) || ! hash_equals($actual, $value)) {
                return false;
            }
        }
        $profileId = $result->getAttribute('candidate_agent_profile_id');

        if (! is_string($profileId)
            || $result->candidate_instruction_snapshot_hash !== $this->instructionHash($run, $profileId)
            || $result->candidate_runtime_profile_hash !== $this->runtimeHash($run, $profileId)) {
            return false;
        }
        try {
            return $result->candidate_prompt_snapshot_hash === $this->prompts->snapshot($run)->hash;
        } catch (\Throwable) {
            return false;
        }
    }

    private function candidateCurrent(Run $run): bool
    {
        return $run->candidate_invalidated_at === null
            && is_string($run->candidate_tree_sha)
            && is_string($run->candidate_diff_hash)
            && is_string($run->candidate_base_sha)
            && $run->candidate_base_sha === $run->run_base_sha
            && $run->candidate_ticket_contract_sha256 === ($run->ticket_contract_sha256
                ?? TicketApproval::query()->whereKey($run->ticket_approval_id)->value('ticket_contract_sha256'))
            && $run->candidate_scope_hash === ($run->effective_scope_hash ?? $run->scope_hash)
            && $run->candidate_config_hash === $run->config_hash
            && $run->candidate_prompt_hash === $run->prompt_hash
            && $run->candidate_security_policy_hash === $run->security_policy_hash;
    }

    private function instructionHash(Run $run, string $profileId): ?string
    {
        $security = ($run->agent_profile_snapshot ?? [])['security_reviewer'] ?? null;
        if (! is_array($security) || ($security['profile_id'] ?? null) !== $profileId
            || ! is_string($provider = $security['provider_profile'] ?? null)) {
            return null;
        }
        $snapshot = ($run->instruction_snapshot ?? [])[$provider] ?? null;

        return is_array($snapshot) && is_string($snapshot['instruction_snapshot_hash'] ?? null)
            ? $snapshot['instruction_snapshot_hash']
            : null;
    }

    private function runtimeHash(Run $run, string $profileId): ?string
    {
        $security = ($run->agent_profile_snapshot ?? [])['security_reviewer'] ?? null;
        if (! is_array($security) || ($security['profile_id'] ?? null) !== $profileId
            || ! is_string($runtimeId = $security['runtime_profile_id'] ?? null)) {
            return null;
        }
        $runtime = ($run->runtime_profile_snapshot ?? [])[$runtimeId] ?? null;

        return is_array($runtime) && is_string($runtime['hash'] ?? null) ? $runtime['hash'] : null;
    }
}
