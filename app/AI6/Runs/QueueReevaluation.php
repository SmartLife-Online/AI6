<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Projects\Models\Project;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

final readonly class QueueReevaluation
{
    public function __construct(
        private AgentProfileRegistry $agentProfiles,
        private PromptCatalog $prompts,
        private SecurityPolicy $securityPolicy,
    ) {}

    public function scheduleApproval(TicketApproval $approval): TicketApprovalEvaluation
    {
        return DB::transaction(function () use ($approval): TicketApprovalEvaluation {
            $evaluation = TicketApprovalEvaluation::query()->firstOrCreate(
                ['ticket_approval_id' => $approval->getKey(), 'state' => 'queued'],
                ['id' => (string) Str::uuid()],
            );
            if ($evaluation->wasRecentlyCreated) {
                Queue::connection('database')->push(new EvaluateTicketApproval($evaluation->id));
            }

            return $evaluation;
        });
    }

    public function scheduleProject(Project $project): void
    {
        $approvalIds = DB::table('ticket_approvals')
            ->where('project_id', $project->getKey())
            ->where('saga_phase', 'complete')
            ->where('queue_state', ApprovalQueueState::QUEUED->value)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->pluck('id');
        foreach ($approvalIds as $approvalId) {
            if (is_string($approvalId)) {
                $this->scheduleApproval(TicketApproval::query()->findOrFail($approvalId));
            }
        }
    }

    public function scheduleAllProjects(): void
    {
        foreach (Project::all()->sortBy('id') as $project) {
            $this->scheduleProject($project);
        }
    }

    public function scheduleTrustedBindingChanges(): void
    {
        $fingerprint = hash('sha256', serialize([
            'agent_profiles' => $this->agentProfiles,
            'prompt_catalog' => $this->prompts,
            'security_policy_hash' => $this->securityPolicy->hash(),
        ]));
        $key = 'ai6.queue.trusted-bindings.sha256';
        $previous = Cache::get($key);
        if (is_string($previous) && hash_equals($previous, $fingerprint)) {
            return;
        }
        $this->scheduleAllProjects();
        Cache::forever($key, $fingerprint);
    }

    public function afterExternalEffect(Project|int $project, QueueReevaluationTrigger $trigger): void
    {
        try {
            if (is_int($project)) {
                $project = Project::query()->findOrFail($project);
            }
            $this->scheduleProject($project);
        } catch (Throwable $exception) {
            try {
                Log::warning('queue_reevaluation_rejected', [
                    'project_id' => $project instanceof Project ? $project->getKey() : $project,
                    'trigger' => $trigger->value,
                    'reason' => class_basename($exception),
                ]);
            } catch (Throwable) {
                // The originating external effect is already durable and must not be failed by telemetry.
            }
        }
    }
}
