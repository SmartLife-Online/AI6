<?php

namespace App\AI6\Git\Actions;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueDeployKeyProvisioning
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
    ) {}

    public function handle(User $actor, Project $project, string $operationId): ControlOperation
    {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        $operationId = strtolower($operationId);

        if (! $this->projectPolicy->provisionDeployKey($actor, $project)) {
            throw new AuthorizationException;
        }

        $snapshot = $this->authorizationSnapshots->capture($actor, $project);
        $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
        $parameters = ControlOperationType::DEPLOY_KEY_PROVISION->parameters(['algorithm' => 'ed25519']);
        $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
        $hash = $this->hasher->hash(
            1,
            (string) $project->project_identifier,
            ControlOperationType::DEPLOY_KEY_PROVISION,
            (string) $actor->getKey(),
            $snapshotJcs,
            $project->control_oid,
            $parameters,
        );

        return DB::transaction(function () use ($actor, $project, $operationId, $snapshot, $snapshotJcs, $parametersJcs, $hash): ControlOperation {
            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }

            $attemptToken = $this->lease->claimInitialDeployKeyProvisioning($project, $operationId);
            if ($attemptToken === null) {
                throw new ControlOperationConflict('Deploy-key provisioning is already active or complete.');
            }

            $operation = ControlOperation::query()->create([
                'id' => $operationId,
                'project_id' => $project->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => ControlOperationType::DEPLOY_KEY_PROVISION,
                'schema_version' => 1,
                'authorization_snapshot' => $snapshot,
                'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $project->control_oid,
                'operation_parameters_jcs' => $parametersJcs,
                'request_hash' => $hash,
                'phase' => ControlOperationPhase::QUEUED,
                'state' => ControlOperationState::QUEUED,
                'current_attempt_token' => $attemptToken,
            ]);

            Queue::connection('database')->push(new ExecuteControlOperation($operationId));

            return $operation;
        });
    }
}
