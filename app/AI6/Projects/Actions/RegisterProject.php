<?php

namespace App\AI6\Projects\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\GitRemoteRejected;
use App\AI6\Git\HostKeyFingerprint;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectIdentifierGenerator;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRegistrationRejected;
use App\AI6\Projects\ProjectRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class RegisterProject
{
    public function __construct(
        private readonly GitRemotePolicy $remotePolicy,
        private readonly ProjectIdentifierGenerator $identifierGenerator,
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    public function handle(
        User $actor,
        string $name,
        string $remote,
        string $controlBranch,
        string $hostKeyFingerprint,
    ): Project {
        if (! $this->projectPolicy->create($actor)) {
            throw new AuthorizationException;
        }

        try {
            $validatedRemote = $this->remotePolicy->validateForRegistration(
                $remote,
                $controlBranch,
                $hostKeyFingerprint,
            );
        } catch (GitRemoteRejected $exception) {
            Log::warning('Project registration metadata rejected.', [
                'failure_class' => $exception->reason,
            ]);

            throw new ProjectRegistrationRejected($exception->reason, previous: $exception);
        }

        $fingerprint = HostKeyFingerprint::parse($hostKeyFingerprint)['fingerprint'];
        if (! $fingerprint instanceof HostKeyFingerprint) {
            throw new LogicException('Validated host key fingerprint could not be resolved.');
        }

        return DB::transaction(function () use ($actor, $name, $validatedRemote, $fingerprint): Project {
            $project = Project::query()->create([
                'name' => $name,
                'remote' => $validatedRemote->remote,
                'control_branch' => $validatedRemote->ref,
                'project_identifier' => $this->identifierGenerator->generate(),
                'host_key_fingerprint' => $fingerprint->canonical(),
                'provisioning_status' => ProjectProvisioningStatus::NOT_PROVISIONED,
            ]);

            ProjectMembership::query()->create([
                'user_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'role' => ProjectRole::ADMIN,
            ]);

            return $project;
        });
    }
}
