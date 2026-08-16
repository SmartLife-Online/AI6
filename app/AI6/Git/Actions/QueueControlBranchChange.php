<?php

namespace App\AI6\Git\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueControlBranchChange
{
    public const STEP_UP_ACTION = 'control_branch.change';

    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
        private ControlOperationConfiguration $configuration,
        private StepUpGuard $stepUp,
    ) {}

    public function handle(
        Request $request,
        User $actor,
        Project $project,
        string $newControlRef,
        string $operationId,
    ): ControlOperation {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->projectPolicy->changeControlBranch($actor, $project)) {
            throw new AuthorizationException;
        }
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->remote === null
            || $project->control_branch === null
            || $project->deploy_key_reference === null
            || $project->host_key_fingerprint === null
            || ! in_array($newControlRef, $this->configuration->managedRefAllowlist, true)
            || hash_equals($project->control_branch, $newControlRef)) {
            throw new ControlOperationConflict('The requested control ref is invalid for this project.');
        }

        $pending = PendingControlBinding::fromProject($project);
        if ($project->control_oid !== null && $pending !== null) {
            throw new ControlOperationConflict('The project has conflicting active and pending control bindings.');
        }
        if ($pending !== null && $pending->ref !== $project->control_branch) {
            throw new ControlOperationConflict('The pending control binding does not match the current control ref.');
        }
        $oldControlOid = $project->control_oid ?? $pending?->oid;
        if ($oldControlOid === null || preg_match('/\A[0-9a-f]{64}\z/D', $oldControlOid) !== 1) {
            throw new ControlOperationConflict('The project has no valid control binding to replace.');
        }

        $this->stepUp->consumeFresh($request, $actor, self::STEP_UP_ACTION);
        $operationId = strtolower($operationId);
        $snapshot = $this->authorizationSnapshots->capture($actor, $project);
        $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
        $parameters = ControlOperationType::CONTROL_BRANCH_CHANGE->parameters([
            'old_control_ref' => $project->control_branch,
            'old_control_oid' => $oldControlOid,
            'expected_binding_version' => $project->control_binding_version,
            'new_control_ref' => $newControlRef,
        ]);
        $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
        $hash = $this->hasher->hash(
            1,
            (string) $project->project_identifier,
            ControlOperationType::CONTROL_BRANCH_CHANGE,
            (string) $actor->getKey(),
            $snapshotJcs,
            $oldControlOid,
            $parameters,
        );

        return DB::transaction(function () use (
            $actor,
            $project,
            $operationId,
            $snapshot,
            $snapshotJcs,
            $parametersJcs,
            $hash,
            $oldControlOid,
        ): ControlOperation {
            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }

            $attemptToken = $this->lease->claimInitialControlOperation(
                $project,
                $operationId,
                requiresInactiveRun: true,
                allowsPendingControlBinding: true,
            );
            if ($attemptToken === null) {
                throw new ControlOperationConflict('Another mutating project operation is active.');
            }

            $operation = ControlOperation::query()->create([
                'id' => $operationId,
                'project_id' => $project->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => ControlOperationType::CONTROL_BRANCH_CHANGE,
                'schema_version' => 1,
                'authorization_snapshot' => $snapshot,
                'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $oldControlOid,
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
