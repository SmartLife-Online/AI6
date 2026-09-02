<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ApprovalQueue
{
    public function enqueue(Project $project, string $approvalId, int $expectedVersion): TicketApproval
    {
        return DB::transaction(function () use ($project, $approvalId, $expectedVersion): TicketApproval {
            DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->firstOrFail();
            $approval = $this->locked($project, $approvalId);
            if ($approval->saga_phase !== 'complete'
                || ! in_array($approval->queue_state, [ApprovalQueueState::AVAILABLE->value, ApprovalQueueState::QUEUED->value], true)
                || $approval->version !== $expectedVersion) {
                throw new ApprovalQueueConflict('stale_approval_version');
            }

            $serializedNow = now()->format('Y-m-d H:i:s');
            $tail = DB::table('ticket_approvals')
                ->where('project_id', $project->getKey())
                ->whereNotNull('queued_at')
                ->max('queued_at');
            $queuedAt = Carbon::createFromFormat('Y-m-d H:i:s', $serializedNow);
            if (is_string($tail)) {
                $serializedTail = Carbon::createFromFormat('Y-m-d H:i:s', $tail);
                if (! $queuedAt->greaterThan($serializedTail)) {
                    $queuedAt = $serializedTail->addSecond();
                }
            }

            $updated = TicketApproval::query()
                ->whereKey($approval->getKey())
                ->where('version', $expectedVersion)
                ->where('saga_phase', 'complete')
                ->whereIn('queue_state', [ApprovalQueueState::AVAILABLE->value, ApprovalQueueState::QUEUED->value])
                ->update([
                    'queue_state' => ApprovalQueueState::QUEUED->value,
                    'queued_at' => $queuedAt,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new ApprovalQueueConflict('stale_approval_version');
            }

            return TicketApproval::query()->findOrFail($approval->getKey());
        });
    }

    public function remove(Project $project, string $approvalId, int $expectedVersion): TicketApproval
    {
        return DB::transaction(function () use ($project, $approvalId, $expectedVersion): TicketApproval {
            $approval = $this->locked($project, $approvalId);
            if ($approval->saga_phase !== 'complete'
                || $approval->queue_state !== ApprovalQueueState::QUEUED->value
                || $approval->version !== $expectedVersion) {
                throw new ApprovalQueueConflict('stale_approval_version');
            }

            $updated = TicketApproval::query()
                ->whereKey($approval->getKey())
                ->where('version', $expectedVersion)
                ->where('queue_state', ApprovalQueueState::QUEUED->value)
                ->update([
                    'queue_state' => ApprovalQueueState::AVAILABLE->value,
                    'queued_at' => null,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new ApprovalQueueConflict('stale_approval_version');
            }

            return TicketApproval::query()->findOrFail($approval->getKey());
        });
    }

    /** @return list<TicketApproval> */
    public function entries(Project $project): array
    {
        $ids = DB::table('ticket_approvals')
            ->where('project_id', $project->getKey())
            ->where('saga_phase', 'complete')
            ->whereIn('queue_state', [
                ApprovalQueueState::QUEUED->value,
                ApprovalQueueState::AVAILABLE->value,
                ApprovalQueueState::CONSUMED->value,
                ApprovalQueueState::CANCELLED->value,
            ])
            ->orderByRaw("CASE queue_state WHEN 'queued' THEN 0 WHEN 'available' THEN 1 WHEN 'consumed' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN queued_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('queued_at')
            ->orderBy('id')
            ->pluck('id');
        $entries = [];
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new ApprovalQueueConflict('invalid_approval_identifier');
            }
            $entries[] = TicketApproval::query()->findOrFail($id);
        }

        return $entries;
    }

    private function locked(Project $project, string $approvalId): TicketApproval
    {
        DB::table('ticket_approvals')
            ->where('id', $approvalId)
            ->where('project_id', $project->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return TicketApproval::query()->findOrFail($approvalId);
    }
}
