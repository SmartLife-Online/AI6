<?php

namespace Tests\Unit\Auth;

final class ExpectedAuthorizationMatrix
{
    /** @return array<string, array<string, bool>> */
    public static function projectActions(): array
    {
        return [
            'appear_in_list' => [
                'admin' => true,
                'viewer' => true,
                'operator' => true,
                'approver' => true,
            ],
            'view_details' => [
                'admin' => true,
                'viewer' => true,
                'operator' => true,
                'approver' => true,
            ],
            'refresh_read_model' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => false,
            ],
            'edit_ticket' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => false,
            ],
            'change_ticket_status' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => true,
            ],
            'refresh_configuration' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => false,
            ],
            'approve_configuration' => [
                'admin' => false,
                'viewer' => false,
                'operator' => false,
                'approver' => true,
            ],
            'approve_ticket' => [
                'admin' => false,
                'viewer' => false,
                'operator' => false,
                'approver' => true,
            ],
            'authorize_gate_evidence' => [
                'admin' => false,
                'viewer' => false,
                'operator' => false,
                'approver' => true,
            ],
            'start_run' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => false,
            ],
            'view_run' => [
                'admin' => true,
                'viewer' => true,
                'operator' => true,
                'approver' => true,
            ],
            'answer_human_request' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => true,
            ],
            'intervene_run' => [
                'admin' => true,
                'viewer' => false,
                'operator' => true,
                'approver' => true,
            ],
            'dispose_finding' => [
                'admin' => false,
                'viewer' => false,
                'operator' => false,
                'approver' => true,
            ],
        ];
    }

    /** @return list<string> */
    public static function globalActions(): array
    {
        return [
            'create_user',
            'deactivate_user',
            'delete_user',
            'grant_global_administrator',
            'revoke_global_administrator',
            'set_membership',
            'remove_membership',
        ];
    }
}
