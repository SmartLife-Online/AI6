<?php

namespace App\AI6\Reviews;

use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;

/** The sole decision for whether an immutable original finding blocks a run. */
final readonly class EffectiveFindingState
{
    public function blocks(Finding $finding, Run $run, ?FindingDisposition $currentDisposition = null): bool
    {
        if (! in_array($finding->original_disposition, [
            FindingOriginalDisposition::MUST_FIX,
            FindingOriginalDisposition::HUMAN_REQUIRED,
        ], true)) {
            return false;
        }

        $effective = $currentDisposition instanceof FindingDisposition
            && $currentDisposition->finding_id === $finding->id
            && $this->matches($currentDisposition, $finding, $run, $this->ticketContract($run))
            ? $currentDisposition
            : $this->currentDisposition($finding, $run);

        return ! $effective instanceof FindingDisposition;
    }

    public function currentDisposition(Finding $finding, Run $run): ?FindingDisposition
    {
        $dispositions = $finding->relationLoaded('dispositions')
            ? $finding->getRelation('dispositions')->sortByDesc('id')->sortByDesc('expected_run_version')
            : $finding->dispositions()->orderByDesc('expected_run_version')->orderByDesc('id')->get();
        if ($dispositions->isEmpty()) {
            return null;
        }
        $ticketContract = $this->ticketContract($run);
        foreach ($dispositions as $disposition) {
            if ($this->matches($disposition, $finding, $run, $ticketContract)) {
                return $disposition;
            }
        }

        return null;
    }

    public function reviewerBindingHash(Finding $finding): string
    {
        return hash('sha256', json_encode([
            $finding->slot_id,
            $finding->provider_profile,
            $finding->model,
            $finding->effort,
            $finding->prompt_profile,
            $finding->round_number,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function matches(FindingDisposition $disposition, Finding $finding, Run $run, mixed $ticketContract): bool
    {
        $bindings = [
            'ticket_contract_sha256' => $ticketContract,
            'config_hash' => $run->config_hash,
            'scope_hash' => $run->scope_hash,
            'prompt_hash' => $run->prompt_hash,
            'instruction_hash' => $run->instruction_hash,
            'runtime_profile_hash' => $run->runtime_profile_hash,
            'agent_profile_hash' => $run->agent_profile_hash,
            'security_policy_hash' => $run->security_policy_hash,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'reviewer_binding_hash' => $this->reviewerBindingHash($finding),
        ];
        foreach ($bindings as $field => $current) {
            $bound = $disposition->getAttribute($field);
            if (! is_string($current) || ! is_string($bound) || ! hash_equals($bound, $current)) {
                return false;
            }
        }

        return true;
    }

    private function ticketContract(Run $run): mixed
    {
        return $run->ticket_contract_sha256
            ?? TicketApproval::query()->whereKey($run->ticket_approval_id)->value('ticket_contract_sha256');
    }
}
