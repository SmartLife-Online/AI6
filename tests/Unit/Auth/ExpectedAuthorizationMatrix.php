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
