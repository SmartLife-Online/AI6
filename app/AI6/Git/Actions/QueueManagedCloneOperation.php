<?php

namespace App\AI6\Git\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationAuthorizationSnapshot;
use App\AI6\Git\ControlOperationConfiguration;
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
use App\AI6\Projects\PendingControlBinding;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueManagedCloneOperation
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
        private ControlOperationConfiguration $configuration,
    ) {}

    public function handle(
        User $actor,
        Project $project,
        ControlOperationType $type,
        string $operationId,
    ): ControlOperation {
        if (! in_array($type, [ControlOperationType::MANAGED_CLONE, ControlOperationType::MANAGED_FETCH], true)) {
            throw new ControlOperationConflict('The requested operation is not a managed-clone operation.');
        }
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->projectPolicy->synchronizeManagedClone($actor, $project)) {
            throw new AuthorizationException;
        }
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->remote === null
            || $project->control_branch === null
            || $project->deploy_key_reference === null
            || ! in_array($project->control_branch, $this->configuration->managedRefAllowlist, true)) {
            throw new ControlOperationConflict('The project is not ready for a managed-clone operation.');
        }
        $pending = PendingControlBinding::fromProject($project);
        if (($type === ControlOperationType::MANAGED_CLONE
                && ($project->control_oid !== null || $pending !== null))
            || ($type === ControlOperationType::MANAGED_FETCH
                && (($project->control_oid === null) === ($pending === null)))
            || ($pending !== null && $pending->ref !== $project->control_branch)) {
            throw new ControlOperationConflict('The operation type does not match the active control binding.');
        }

        $operationId = strtolower($operationId);
        $snapshot = $this->authorizationSnapshots->capture($actor, $project);
        $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
        $parameterValues = [
            'control_ref' => $project->control_branch,
            'expected_binding_version' => $project->control_binding_version,
        ];
        if ($type === ControlOperationType::MANAGED_FETCH) {
            $parameterValues += [
                'pending_source_operation_id' => $pending?->sourceOperationId,
                'pending_binding_version' => $pending?->version,
                'pending_control_oid' => $pending?->oid,
            ];
        }
        $parameters = $type->parameters($parameterValues);
        $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
        $hash = $this->hasher->hash(
            1,
            (string) $project->project_identifier,
            $type,
            (string) $actor->getKey(),
            $snapshotJcs,
            $project->control_oid,
            $parameters,
        );

        return DB::transaction(function () use (
            $actor,
            $project,
            $type,
            $operationId,
            $snapshot,
            $snapshotJcs,
            $parametersJcs,
            $hash,
        ): ControlOperation {
            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }

            $attemptToken = $this->lease->claimInitialControlOperation($project, $operationId);
            if ($attemptToken === null) {
                throw new ControlOperationConflict('Another mutating project operation is active.');
            }

            $operation = ControlOperation::query()->create([
                'id' => $operationId,
                'project_id' => $project->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => $type,
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
