<?php

namespace App\AI6\Runs\Jobs;

use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\ApprovalFreshness;
use App\AI6\Runs\ApprovalSelectionFactory;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ApprovalStartEligibility;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\RunState;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class EvaluateTicketApproval implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $evaluationId)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(
        ControlOperationRuntimeIdentity $runtimeIdentity,
        ApprovalSelectionFactory $selections,
        ApprovalSnapshotFactory $snapshots,
        ApprovalFreshness $freshness,
        ApprovalStartEligibility $eligibility,
        EffectiveProjectConfiguration $configurations,
        TicketV1Parser $tickets,
    ): void {
        if ($runtimeIdentity->runtimeRole !== 'worker') {
            throw new RuntimeException('Approval evaluations may execute only in the worker role.');
        }
        $evaluation = TicketApprovalEvaluation::query()->findOrFail($this->evaluationId);
        if ($evaluation->state !== 'queued') {
            return;
        }
        try {
            $approval = TicketApproval::query()->findOrFail($evaluation->ticket_approval_id);
            $project = Project::query()->findOrFail($approval->project_id);
            $readModel = TicketReadModel::query()
                ->where('project_id', $project->getKey())
                ->where('relative_path', $approval->relative_path)
                ->firstOrFail();
            $binding = $configurations->for($project);
            $capabilitiesAvailable = true;
            $currentHashes = [];
            try {
                $snapshot = $snapshots->create(
                    $project,
                    $readModel,
                    $selections->fromArray($approval->agent_profile_snapshot),
                    $approval->snapshot_context_id,
                );
                $currentHashes = [
                    'scope_hash' => $snapshot->scopeHash,
                    'prompt_hash' => $snapshot->promptHash,
                    'instruction_hash' => $snapshot->instructionHash,
                    'runtime_profile_hash' => $snapshot->runtimeProfileHash,
                    'agent_profile_hash' => $snapshot->agentProfileHash,
                    'security_policy_hash' => $snapshot->securityPolicyHash,
                ];
            } catch (Throwable) {
                $capabilitiesAvailable = false;
            }
            $dependencyState = $this->dependencyState($project, $readModel, $tickets);
            $decision = $eligibility->decide(
                array_merge(
                    $freshness->reasons($approval, $project, $readModel, $binding->configHash, $currentHashes),
                    $dependencyState['reasons'],
                ),
                $dependencyState['statuses'],
                $binding->configuration->values['dependency_satisfied_statuses'],
                $capabilitiesAvailable,
                Run::query()
                    ->where('project_id', $project->getKey())
                    ->whereNotIn('state', [RunState::COMPLETED, RunState::CANCELLED])
                    ->exists(),
                $approval->queue_state,
            );
            TicketApprovalEvaluation::query()
                ->whereKey($evaluation->id)
                ->where('state', 'queued')
                ->update([
                    'state' => 'ready',
                    'eligible' => $decision['eligible'],
                    'reasons' => $decision['reasons'],
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            TicketApprovalEvaluation::query()
                ->whereKey($evaluation->id)
                ->where('state', 'queued')
                ->update([
                    'state' => 'conflict',
                    'eligible' => false,
                    'reasons' => ['approval_evaluation_failed'],
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array{statuses: array<string, string>, reasons: list<string>} */
    private function dependencyState(Project $project, TicketReadModel $readModel, TicketV1Parser $parser): array
    {
        try {
            $document = $parser->parse($readModel->redacted_content);
        } catch (TicketParseException) {
            return ['statuses' => [], 'reasons' => []];
        }
        $dependencies = is_array($document->frontmatter['depends_on'] ?? null)
            ? array_values(array_filter($document->frontmatter['depends_on'], is_string(...)))
            : [];
        $matches = array_fill_keys($dependencies, []);
        foreach (TicketReadModel::query()->where('project_id', $project->getKey())->get() as $candidate) {
            try {
                $frontmatter = $parser->parse($candidate->redacted_content)->frontmatter;
            } catch (TicketParseException) {
                continue;
            }
            $id = $frontmatter['id'] ?? null;
            $status = $frontmatter['status'] ?? null;
            if (is_string($id) && is_string($status) && in_array($id, $dependencies, true)) {
                $matches[$id][] = $status;
            }
        }
        $statuses = [];
        $reasons = [];
        ksort($matches, SORT_STRING);
        foreach ($matches as $dependency => $dependencyStatuses) {
            if (count($dependencyStatuses) > 1) {
                $reasons[] = 'dependency_ambiguous:'.$dependency;

                continue;
            }
            $statuses[$dependency] = $dependencyStatuses[0] ?? 'missing';
        }

        return ['statuses' => $statuses, 'reasons' => $reasons];
    }
}
