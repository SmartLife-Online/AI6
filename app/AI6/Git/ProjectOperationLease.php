<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class ProjectOperationLease
{
    public function __construct(private ControlOperationConfiguration $configuration) {}

    public function claimInitialDeployKeyProvisioning(Project $project, string $operationId): ?int
    {
        $now = Date::now();
        $updated = Project::query()
            ->whereKey($project->getKey())
            ->whereIn('provisioning_status', [
                ProjectProvisioningStatus::NOT_PROVISIONED,
                ProjectProvisioningStatus::PROVISIONING_FAILED,
            ])
            ->whereNull('provisioning_operation_id')
            ->whereNull('operation_lock_operation_id')
            ->update([
                'provisioning_status' => ProjectProvisioningStatus::PROVISIONING,
                'provisioning_operation_id' => $operationId,
                'operation_lock_operation_id' => $operationId,
                'operation_lock_lease_expires_at' => $now->copy()->addSeconds($this->configuration->leaseSeconds),
                'operation_lock_heartbeat_at' => $now,
                'operation_lock_attempt_token' => DB::raw('operation_lock_attempt_token + 1'),
            ]);
        if ($updated !== 1) {
            return null;
        }

        return (int) Project::query()
            ->whereKey($project->getKey())
            ->value('operation_lock_attempt_token');
    }

    public function claim(ControlOperation $operation, string $bootId): ?int
    {
        return DB::transaction(function () use ($operation, $bootId): ?int {
            $now = Date::now();
            $lease = $now->copy()->addSeconds($this->configuration->leaseSeconds);
            $initialClaim = $operation->state === ControlOperationState::QUEUED
                && $operation->current_attempt_token !== null;
            if ($initialClaim) {
                $updated = Project::query()
                    ->whereKey($operation->project_id)
                    ->where('operation_lock_operation_id', $operation->id)
                    ->where('operation_lock_attempt_token', $operation->current_attempt_token)
                    ->update([
                        'operation_lock_lease_expires_at' => $lease,
                        'operation_lock_heartbeat_at' => $now,
                    ]);
            } else {
                $updated = Project::query()
                    ->whereKey($operation->project_id)
                    ->where(function (Builder $query) use ($operation, $now): void {
                        $query->whereNull('operation_lock_operation_id')
                            ->orWhere(function (Builder $owned) use ($operation, $now): void {
                                $owned->where('operation_lock_operation_id', $operation->id)
                                    ->where(function (Builder $expired) use ($now): void {
                                        $expired->whereNull('operation_lock_lease_expires_at')
                                            ->orWhere('operation_lock_lease_expires_at', '<=', $now);
                                    });
                            });
                    })
                    ->update([
                        'operation_lock_operation_id' => $operation->id,
                        'operation_lock_lease_expires_at' => $lease,
                        'operation_lock_heartbeat_at' => $now,
                        'operation_lock_attempt_token' => DB::raw('operation_lock_attempt_token + 1'),
                    ]);
            }

            if ($updated !== 1) {
                return null;
            }

            $project = Project::query()->findOrFail($operation->project_id);
            $token = $initialClaim ? $operation->current_attempt_token : $project->operation_lock_attempt_token;
            $recovery = $operation->state === ControlOperationState::RECOVERY_REQUIRED;
            $claimed = ControlOperation::query()
                ->whereKey($operation->id)
                ->whereIn('state', [
                    ControlOperationState::QUEUED->value,
                    ControlOperationState::RUNNING->value,
                    ControlOperationState::RECOVERY_REQUIRED->value,
                ])
                ->update([
                    'phase' => $operation->phase === ControlOperationPhase::QUEUED
                        ? ControlOperationPhase::CLAIMED
                        : $operation->phase,
                    'state' => $recovery ? ControlOperationState::RECOVERY_REQUIRED : ControlOperationState::RUNNING,
                    'attempts' => DB::raw('attempts + 1'),
                    'current_attempt_token' => $token,
                    'lease_boot_id' => $bootId,
                    'started_at' => $operation->started_at ?? $now,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                throw new ControlOperationConflict('The operation state changed while its project lease was claimed.');
            }

            return $token;
        });
    }

    public function claimRecoveryInspection(ControlOperation $operation): ?int
    {
        if ($operation->state !== ControlOperationState::RECOVERY_REQUIRED
            || $operation->current_attempt_token === null) {
            return null;
        }

        $now = Date::now();
        $updated = Project::query()
            ->whereKey($operation->project_id)
            ->where('operation_lock_operation_id', $operation->id)
            ->where('operation_lock_attempt_token', $operation->current_attempt_token)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('operation_lock_lease_expires_at')
                    ->orWhere('operation_lock_lease_expires_at', '<=', $now);
            })
            ->update([
                'operation_lock_heartbeat_at' => $now,
                'operation_lock_lease_expires_at' => $now->copy()->addSeconds($this->configuration->leaseSeconds),
            ]);

        return $updated === 1 ? $operation->current_attempt_token : null;
    }

    /** @phpstan-impure */
    public function owns(string $operationId, int $projectId, int $attemptToken): bool
    {
        return Project::query()
            ->whereKey($projectId)
            ->where('operation_lock_operation_id', $operationId)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->where('operation_lock_lease_expires_at', '>', Date::now())
            ->exists();
    }

    public function heartbeat(string $operationId, int $projectId, int $attemptToken): bool
    {
        $now = Date::now();

        return Project::query()
            ->whereKey($projectId)
            ->where('operation_lock_operation_id', $operationId)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->where('operation_lock_lease_expires_at', '>', $now)
            ->update([
                'operation_lock_heartbeat_at' => $now,
                'operation_lock_lease_expires_at' => $now->copy()->addSeconds($this->configuration->leaseSeconds),
            ]) === 1;
    }

    public function release(string $operationId, int $projectId, int $attemptToken): bool
    {
        return Project::query()
            ->whereKey($projectId)
            ->where('operation_lock_operation_id', $operationId)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->whereExists(function ($query) use ($operationId): void {
                $query->selectRaw('1')
                    ->from('control_operations')
                    ->whereColumn('control_operations.project_id', 'projects.id')
                    ->where('control_operations.id', $operationId)
                    ->whereIn('control_operations.state', [
                        ControlOperationState::COMPLETED->value,
                        ControlOperationState::FAILED->value,
                        ControlOperationState::ABANDONED->value,
                    ]);
            })
            ->update([
                'operation_lock_operation_id' => null,
                'operation_lock_lease_expires_at' => null,
                'operation_lock_heartbeat_at' => null,
            ]) === 1;
    }

    public function releaseTerminal(ControlOperation $operation, int $attemptToken): bool
    {
        if ($this->release($operation->id, $operation->project_id, $attemptToken)) {
            return true;
        }

        $stillOwned = Project::query()
            ->whereKey($operation->project_id)
            ->where('operation_lock_operation_id', $operation->id)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->exists();
        if (! $stillOwned) {
            return true;
        }

        ControlOperation::query()
            ->whereKey($operation->id)
            ->whereIn('state', [
                ControlOperationState::COMPLETED->value,
                ControlOperationState::FAILED->value,
                ControlOperationState::ABANDONED->value,
            ])
            ->update([
                'last_error' => 'Die terminale Projektsperre konnte nicht freigegeben werden und wird erneut abgeglichen.',
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);

        return false;
    }

    public function expire(string $operationId, int $projectId, int $attemptToken): bool
    {
        return Project::query()
            ->whereKey($projectId)
            ->where('operation_lock_operation_id', $operationId)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->update([
                'operation_lock_lease_expires_at' => Date::now()->subSecond(),
            ]) === 1;
    }
}
