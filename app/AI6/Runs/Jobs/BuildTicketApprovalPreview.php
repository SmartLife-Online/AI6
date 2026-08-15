<?php

namespace App\AI6\Runs\Jobs;

use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\InstructionResolutionException;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Runs\ApprovalSelectionFactory;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\Models\TicketApprovalPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class BuildTicketApprovalPreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $previewId)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(ControlOperationRuntimeIdentity $runtimeIdentity, ApprovalSelectionFactory $selections, ApprovalSnapshotFactory $snapshots): void
    {
        if ($runtimeIdentity->runtimeRole !== 'worker') {
            throw new RuntimeException('Approval previews may execute only in the worker role.');
        }
        $preview = TicketApprovalPreview::query()->findOrFail($this->previewId);
        if ($preview->state !== 'queued') {
            return;
        }
        try {
            $project = Project::query()->findOrFail($preview->project_id);
            $readModel = TicketReadModel::query()->findOrFail($preview->ticket_read_model_id);
            if ($readModel->project_id !== $project->getKey()
                || $project->control_oid === null
                || ! hash_equals($preview->reviewed_control_sha, $project->control_oid)
                || ! hash_equals($preview->reviewed_control_sha, $readModel->control_commit)
                || ! hash_equals($preview->reviewed_ticket_blob_sha, $readModel->blob_sha)
                || ! hash_equals($preview->ticket_contract_sha256, (string) $readModel->ticket_contract_sha256)
                || $preview->control_generation !== $readModel->control_generation) {
                throw new RuntimeException('preview_binding_changed');
            }
            $snapshot = $snapshots->create($project, $readModel, $selections->fromArray($preview->selection_snapshot), $preview->id);
            DB::transaction(static function () use ($preview, $snapshot): void {
                $updated = TicketApprovalPreview::query()
                    ->whereKey($preview->id)
                    ->where('state', 'queued')
                    ->update([
                        'state' => 'ready',
                        'approval_snapshot' => $snapshot->jsonSerialize(),
                        'approval_snapshot_hash' => $snapshot->aggregateHash,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The approval preview lost its state binding.');
                }
            });
        } catch (Throwable $exception) {
            TicketApprovalPreview::query()
                ->whereKey($preview->id)
                ->where('state', 'queued')
                ->update([
                    'state' => 'conflict',
                    'error_code' => $this->errorCode($exception),
                    'updated_at' => now(),
                ]);
        }
    }

    private function errorCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof InstructionResolutionException => 'instruction_'.$exception->reason->value,
            $exception instanceof PromptRenderingException => 'prompt_'.$exception->reason->value,
            $exception instanceof AgentProfileSelectionException => 'agent_profile_'.$exception->reason->value,
            $exception->getMessage() === 'preview_binding_changed' => 'preview_binding_changed',
            default => 'preview_snapshot_failed',
        };
    }
}
