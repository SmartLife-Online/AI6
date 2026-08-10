<?php

namespace App\AI6\Projects\Models;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $remote
 * @property string|null $control_branch
 * @property string|null $project_identifier
 * @property string|null $host_key_fingerprint
 * @property string|null $deploy_key_reference
 * @property string|null $public_deploy_key
 * @property ProjectProvisioningStatus $provisioning_status
 * @property string|null $provisioning_operation_id
 * @property string|null $operation_lock_operation_id
 * @property Carbon|null $operation_lock_lease_expires_at
 * @property Carbon|null $operation_lock_heartbeat_at
 * @property int $operation_lock_attempt_token
 * @property int $control_generation
 * @property string|null $control_oid
 * @property int $control_binding_version
 * @property string|null $pending_control_ref
 * @property string|null $pending_control_oid
 * @property string|null $pending_control_operation_id
 * @property-read Collection<int, TicketReadModel> $ticketReadModels
 */
final class Project extends Model
{
    /**
     * The four operation_lock_* columns are deliberately absent here. They are written
     * exclusively by the compare-and-swap query builder updates in ProjectOperationLease,
     * never through Eloquent mass assignment, so no fillable entry may reopen that path.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'remote',
        'control_branch',
        'project_identifier',
        'host_key_fingerprint',
        'deploy_key_reference',
        'public_deploy_key',
        'provisioning_status',
        'provisioning_operation_id',
        'control_generation',
        'control_oid',
        'control_binding_version',
        'pending_control_ref',
        'pending_control_oid',
        'pending_control_operation_id',
    ];

    /** @return HasMany<ProjectMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return HasMany<ControlOperation, $this> */
    public function controlOperations(): HasMany
    {
        return $this->hasMany(ControlOperation::class);
    }

    /** @return HasMany<TicketReadModel, $this> */
    public function ticketReadModels(): HasMany
    {
        return $this->hasMany(TicketReadModel::class);
    }

    public function publicDeployKeyForDisplay(): ?string
    {
        return $this->provisioning_status->exposesPublicDeployKey()
            ? $this->public_deploy_key
            : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provisioning_status' => ProjectProvisioningStatus::class,
            'operation_lock_lease_expires_at' => 'datetime',
            'operation_lock_heartbeat_at' => 'datetime',
            'operation_lock_attempt_token' => 'integer',
            'control_generation' => 'integer',
            'control_binding_version' => 'integer',
        ];
    }
}
