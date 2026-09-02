<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Tickets\TicketDependencyEligibility;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketV1Parser;

final readonly class QueueEligibility
{
    public function __construct(
        private ApprovalFreshness $freshness,
        private ApprovalSnapshotVerifier $snapshots,
        private ApprovalStartEligibility $decision,
        private EffectiveProjectConfiguration $configurations,
        private TicketV1Parser $parser,
        private TicketDependencyEligibility $dependencies,
        private AgentProfileRegistry $agentProfiles,
    ) {}

    /** @return array{eligible: bool, reasons: list<string>} */
    public function decide(TicketApproval $approval, Project $project): array
    {
        return $this->resolve($approval, $project)->toArray();
    }

    public function resolve(TicketApproval $approval, Project $project): QueueEligibilityDecision
    {
        $projectReasons = [];
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED) {
            $projectReasons[] = 'project_not_provisioned';
        }
        if (! is_string($project->project_identifier) || $project->project_identifier === '') {
            $projectReasons[] = 'project_identifier_missing';
        }
        if (! is_string($project->control_oid) || $project->control_oid === '') {
            $projectReasons[] = 'control_head_unverified';
        }

        $readModel = TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->where('relative_path', $approval->relative_path)
            ->where('control_commit', $project->control_oid)
            ->where('control_generation', $project->control_generation)
            ->latest('generated_at')
            ->latest('id')
            ->first();
        $readModel ??= TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->where('relative_path', $approval->relative_path)
            ->latest('generated_at')
            ->latest('id')
            ->first();
        if (! $readModel instanceof TicketReadModel) {
            return new QueueEligibilityDecision(false, ['ticket_read_model_missing'], null);
        }

        $binding = $this->configurations->for($project);
        $reasons = array_merge($projectReasons, $this->freshness->reasons($approval, $project, $readModel, $binding->configHash));
        if ($readModel->document_state !== TicketDocumentState::VALID) {
            $reasons[] = 'ticket_document_invalid';
        }
        if ($readModel->redaction_state !== TicketReadModelRedactionState::CLEAR) {
            $reasons[] = 'ticket_redaction_not_clear';
        }
        $readModelBlobSha = $readModel->getAttribute('blob_sha');
        if (! is_string($readModelBlobSha)
            || ! hash_equals($readModelBlobSha, hash('sha256', 'blob '.strlen($readModel->redacted_content)."\0".$readModel->redacted_content))) {
            $reasons[] = 'ticket_blob_inconsistent';
        }
        if ($this->freshness->readModelGenerationIsCurrent($project, $readModel)
            && ($project->control_oid === null || ! hash_equals($project->control_oid, $readModel->control_commit))) {
            $reasons[] = 'control_head_unverified';
        }
        if (! $this->snapshots->matches($approval, $project, $readModel)) {
            $reasons[] = 'approval_snapshot_changed';
        }

        $dependencyState = $this->dependencyState($project, $approval->ticket_id);
        /** @var list<string> $satisfiedStatuses */
        $satisfiedStatuses = $binding->configuration->values['dependency_satisfied_statuses'];

        $decision = $this->decision->decide(
            array_values(array_unique(array_merge($reasons, $dependencyState['reasons']))),
            $dependencyState['statuses'],
            $satisfiedStatuses,
            $this->capabilitiesAvailable($approval),
            Run::query()
                ->where('project_id', $project->getKey())
                ->whereNotIn('state', [RunState::COMPLETED, RunState::CANCELLED])
                ->exists(),
            $approval->queue_state,
        );

        return new QueueEligibilityDecision($decision['eligible'], $decision['reasons'], $readModel);
    }

    /** @return array{eligible: bool, reasons: list<string>} */
    public function storedDecision(TicketApproval $approval, ?TicketApprovalEvaluation $evaluation): array
    {
        if ($approval->queue_state === ApprovalQueueState::CANCELLED->value) {
            return ['eligible' => false, 'reasons' => ['approval_cancelled']];
        }
        if ($approval->queue_state !== ApprovalQueueState::QUEUED->value) {
            return ['eligible' => false, 'reasons' => ['approval_not_queued']];
        }
        if (! $evaluation instanceof TicketApprovalEvaluation) {
            return ['eligible' => false, 'reasons' => ['evaluation_pending']];
        }
        if ($evaluation->state === 'queued') {
            return ['eligible' => false, 'reasons' => ['evaluation_pending']];
        }

        return [
            'eligible' => $evaluation->eligible === true,
            'reasons' => is_array($evaluation->reasons) ? $evaluation->reasons : ['approval_evaluation_failed'],
        ];
    }

    private function capabilitiesAvailable(TicketApproval $approval): bool
    {
        $profileIds = [];
        foreach (['implementation', 'security_reviewer'] as $key) {
            $entry = $approval->agent_profile_snapshot[$key] ?? null;
            if (is_array($entry) && is_string($entry['profile_id'] ?? null)) {
                $profileIds[] = $entry['profile_id'];
            }
        }
        foreach (['reviewers', 'verifier_candidates'] as $key) {
            $entries = $approval->agent_profile_snapshot[$key] ?? null;
            if (! is_array($entries)) {
                return false;
            }
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_string($entry['profile_id'] ?? null)) {
                    return false;
                }
                $profileIds[] = $entry['profile_id'];
            }
        }

        try {
            foreach (array_unique($profileIds) as $profileId) {
                if (! $this->agentProfiles->get($profileId)->capabilityStatus->selectable()) {
                    return false;
                }
            }
        } catch (AgentProfileSelectionException) {
            return false;
        }

        return $profileIds !== [];
    }

    /** @return array{statuses: array<string, string>, reasons: list<string>} */
    private function dependencyState(Project $project, string $subjectId): array
    {
        $tickets = [];
        foreach (TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->where('control_commit', $project->control_oid)
            ->where('control_generation', $project->control_generation)
            ->get() as $readModel) {
            try {
                $frontmatter = $this->parser->parse($readModel->redacted_content)->frontmatter;
            } catch (TicketParseException) {
                continue;
            }
            $id = $frontmatter['id'] ?? null;
            $status = $frontmatter['status'] ?? null;
            $dependsOn = $frontmatter['depends_on'] ?? null;
            if (! is_string($id) || ! is_string($status) || ! is_array($dependsOn)) {
                continue;
            }
            $tickets[] = [
                'id' => $id,
                'status' => $status,
                'depends_on' => array_values(array_filter($dependsOn, 'is_string')),
            ];
        }

        return $this->dependencies->resolve($subjectId, $tickets);
    }
}
