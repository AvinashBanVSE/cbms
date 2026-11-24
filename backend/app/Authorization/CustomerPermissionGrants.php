<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CustomerPermissionGrants
{
    public static function forUser(string $user): Builder
    {
        $roles = DB::table('users as subject')->crossJoinSub(OrganizationBusinessRoles::permissionsFor($user), 'roles')
            ->where('subject.id', $user)->select('subject.id as user_id', 'roles.business_id', 'roles.action');

        return DB::table('customer_permissions')->where('user_id', $user)->select('user_id', 'business_id', 'action')->union($roles);
    }
}
