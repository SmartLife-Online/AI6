<?php

namespace App\AI6\Git;

enum ControlOperationType: string
{
    case DEPLOY_KEY_PROVISION = 'deploy_key_provision';
    case MANAGED_CLONE = 'managed_clone';
    case MANAGED_FETCH = 'managed_fetch';
    case CONTROL_BRANCH_CHANGE = 'control_branch_change';
    case TICKET_REFRESH = 'ticket_refresh';
    case TICKET_EDIT = 'ticket_edit';
    case TICKET_STATUS_CHANGE = 'ticket_status_change';
    case CONFIG_REFRESH = 'config_refresh';
    case TICKET_APPROVAL = 'ticket_approval';
    case RUN_START = 'run_start';
    case CONTRACT_AMENDMENT = 'contract_amendment';

    /** @var array<string, list<string>> */
    public const PARAMETER_FIELDS = [
        'deploy_key_provision' => ['algorithm'],
        'managed_clone' => ['control_ref', 'expected_binding_version'],
        'managed_fetch' => [
            'control_ref',
            'expected_binding_version',
            'pending_source_operation_id',
            'pending_binding_version',
            'pending_control_oid',
        ],
        'control_branch_change' => [
            'old_control_ref',
            'old_control_oid',
            'expected_binding_version',
            'new_control_ref',
        ],
        'ticket_refresh' => [
            'refresh_base_path',
            'relative_path',
            'validation_profile',
            'effective_config_hash',
        ],
        'ticket_edit' => [
            'relative_path',
            'expected_binding_version',
        ],
        'ticket_status_change' => [
            'relative_path',
            'expected_binding_version',
            'status_operation',
        ],
        'config_refresh' => ['config_path'],
        'ticket_approval' => [
            'relative_path',
            'expected_binding_version',
            'status_operation',
        ],
        'run_start' => [
            'approval_id',
            'run_type',
            'relative_path',
            'expected_binding_version',
        ],
        'contract_amendment' => [
            'run_id',
            'relative_path',
            'expected_binding_version',
            'human_request_id',
        ],
    ];

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, scalar|null>
     */
    public function parameters(array $values): array
    {
        $parameters = [];
        foreach (self::PARAMETER_FIELDS[$this->value] as $field) {
            if (! array_key_exists($field, $values)) {
                throw new ControlOperationConflict(sprintf('Operation parameter %s is missing.', $field));
            }

            $parameters[$field] = $values[$field];
        }

        if (count($parameters) !== count($values)) {
            throw new ControlOperationConflict('The operation contains an unknown parameter.');
        }

        return $parameters;
    }
}
