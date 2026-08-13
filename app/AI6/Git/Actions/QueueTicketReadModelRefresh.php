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
use App\AI6\Git\RefreshPathPolicy;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class QueueTicketReadModelRefresh
{
    public function __construct(
        private CanonicalJson $canonicalJson,
        private ControlOperationHasher $hasher,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private ProjectOperationLease $lease,
        private EffectiveProjectConfiguration $projectConfiguration,
    ) {}

    public function handle(
        User $actor,
        Project $project,
        string $candidatePath,
        string $operationId,
    ): ControlOperation {
        if (! ManagedProjectPath::validOperationIdentifier($operationId)) {
            throw new ControlOperationConflict('The operation identifier is invalid.');
        }
        if (! $this->projectPolicy->refreshReadModel($actor, $project)) {
            throw new AuthorizationException;
        }

        $operationId = strtolower($operationId);

        return DB::transaction(function () use ($actor, $project, $candidatePath, $operationId): ControlOperation {
            $currentProject = Project::query()->findOrFail($project->getKey());
            if (! $this->projectPolicy->refreshReadModel($actor, $currentProject)) {
                throw new AuthorizationException;
            }

            $binding = $this->projectConfiguration->for($currentProject);
            $relativePath = RefreshPathPolicy::canonicalizeCandidateForBase($candidatePath, $binding->configuration->ticketsPath());
            if ($currentProject->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
                || $currentProject->project_identifier === null
                || $currentProject->control_oid === null
                || preg_match('/\A[0-9a-f]{64}\z/D', $currentProject->control_oid) !== 1
                || $currentProject->pending_control_oid !== null) {
                throw new ControlOperationConflict('The project has no refreshable active control binding.');
            }

            $snapshot = $this->authorizationSnapshots->capture($actor, $currentProject);
            $snapshotJcs = $this->canonicalJson->normalizeAndEncode($snapshot);
            $parameters = ControlOperationType::TICKET_REFRESH->parameters([
                'refresh_base_path' => $binding->configuration->ticketsPath(),
                'relative_path' => $relativePath,
                'validation_profile' => $binding->configuration->ticketValidationProfile()->value,
                'effective_config_hash' => $binding->configHash,
            ]);
            $parametersJcs = $this->canonicalJson->normalizeAndEncode($parameters);
            $hash = $this->hasher->hash(
                1,
                (string) $currentProject->project_identifier,
                ControlOperationType::TICKET_REFRESH,
                (string) $actor->getKey(),
                $snapshotJcs,
                $currentProject->control_oid,
                $parameters,
            );

            $existing = ControlOperation::query()->find($operationId);
            if ($existing instanceof ControlOperation) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ControlOperationConflict('The operation identifier is already bound to another request.');
                }

                return $existing;
            }

            $attemptToken = $this->lease->claimInitialControlOperation($currentProject, $operationId);
            if ($attemptToken === null) {
                throw new ControlOperationConflict('Another mutating project operation is active.');
            }

            $operation = ControlOperation::query()->create([
                'id' => $operationId,
                'project_id' => $currentProject->getKey(),
                'actor_id' => $actor->getKey(),
                'operation_type' => ControlOperationType::TICKET_REFRESH,
                'schema_version' => 1,
                'authorization_snapshot' => $snapshot,
                'authorization_snapshot_jcs' => $snapshotJcs,
                'expected_control_commit' => $currentProject->control_oid,
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
