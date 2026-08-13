<?php

namespace App\AI6\Projects\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationAuthorizationSnapshot;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationHasher;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueProjectConfigRefresh
{
    public const CONFIG_PATH = '.ai6/config.yaml';

    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ProjectPolicy $policy,
        private ControlOperationAuthorizationSnapshot $authorization,
        private ProjectOperationLease $lease,
    ) {}

    public function handle(User $actor, Project $project, string $operationId): ControlOperation
    {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->policy->refreshConfiguration($actor, $project)) {
            throw new AuthorizationException;
        }
        $operationId = strtolower($operationId);

        return DB::transaction(function () use ($actor, $project, $operationId): ControlOperation {
            $current = Project::query()->findOrFail($project->getKey());
            if (! $this->policy->refreshConfiguration($actor, $current)) {
                throw new AuthorizationException;
            }
            if ($current->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
                || $current->project_identifier === null || $current->control_oid === null
                || $current->pending_control_oid !== null) {
                throw new ControlOperationConflict('The project has no refreshable active control binding.');
            }
            $snapshot = $this->authorization->capture($actor, $current);
            $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
            $parameters = ControlOperationType::CONFIG_REFRESH->parameters(['config_path' => self::CONFIG_PATH]);
            $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
            $hash = $this->hasher->hash(1, (string) $current->project_identifier, ControlOperationType::CONFIG_REFRESH,
                (string) $actor->getKey(), $snapshotJcs, $current->control_oid, $parameters);

            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }
            $attempt = $this->lease->claimInitialControlOperation($current, $operationId);
            if ($attempt === null) {
                throw new ControlOperationConflict('Another mutating project operation is active.');
            }
            $operation = ControlOperation::query()->create([
                'id' => $operationId, 'project_id' => $current->getKey(), 'actor_id' => $actor->getKey(),
                'operation_type' => ControlOperationType::CONFIG_REFRESH, 'schema_version' => 1,
                'authorization_snapshot' => $snapshot, 'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $current->control_oid, 'operation_parameters_jcs' => $parametersJcs,
                'request_hash' => $hash, 'phase' => ControlOperationPhase::QUEUED,
                'state' => ControlOperationState::QUEUED, 'current_attempt_token' => $attempt,
            ]);
            Queue::connection('database')->push(new ExecuteControlOperation($operationId));

            return $operation;
        });
    }
}
