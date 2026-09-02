<?php

namespace App\AI6\Runs;

use App\AI6\Projects\ControlGeneration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\Models\TicketApproval;

final readonly class ApprovalFreshness
{
    public function __construct(private ControlGeneration $generations) {}

    /**
     * @param  array{prompt_hash?: string, instruction_hash?: string, runtime_profile_hash?: string, agent_profile_hash?: string, security_policy_hash?: string, scope_hash?: string}  $currentHashes
     * @return list<string>
     */
    public function reasons(TicketApproval $approval, Project $project, TicketReadModel $readModel, string $currentConfigHash, array $currentHashes = []): array
    {
        $reasons = [];
        if ($approval->saga_phase !== 'complete') {
            $reasons[] = 'approval_not_finalized';
        }
        if ($approval->queue_state === ApprovalQueueState::CANCELLED->value) {
            $reasons[] = 'approval_cancelled';
        }
        if ($approval->project_id !== $project->getKey() || $readModel->project_id !== $project->getKey()) {
            $reasons[] = 'project_binding_changed';
        }
        if (! $this->generations->isCurrent($project, $approval->control_generation)
            || ! $this->generations->isCurrent($project, $readModel->control_generation)) {
            $reasons[] = 'control_generation_changed';
        }
        $readModelBlobSha = $readModel->getAttribute('blob_sha');
        if (! is_string($approval->approved_ticket_blob_sha)
            || ! is_string($readModelBlobSha)
            || ! hash_equals($approval->approved_ticket_blob_sha, $readModelBlobSha)) {
            $reasons[] = 'ticket_blob_changed';
        }
        if (! is_string($readModel->ticket_contract_sha256) || ! hash_equals($approval->ticket_contract_sha256, $readModel->ticket_contract_sha256)) {
            $reasons[] = 'ticket_contract_changed';
        }
        if (! hash_equals($approval->config_hash, $currentConfigHash)) {
            $reasons[] = 'config_changed';
        }
        foreach ([
            'scope_hash' => 'scope_changed',
            'prompt_hash' => 'prompt_changed',
            'instruction_hash' => 'instructions_changed',
            'runtime_profile_hash' => 'runtime_profile_changed',
            'agent_profile_hash' => 'agent_profiles_changed',
            'security_policy_hash' => 'security_policy_changed',
        ] as $field => $reason) {
            if (isset($currentHashes[$field]) && ! hash_equals((string) $approval->{$field}, $currentHashes[$field])) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    public function readModelGenerationIsCurrent(Project $project, TicketReadModel $readModel): bool
    {
        return $this->generations->isCurrent($project, $readModel->control_generation);
    }
}
