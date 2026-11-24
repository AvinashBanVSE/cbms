<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OrganizationBusinessRoles
{
    public static function permissionsFor(string $user): Builder
    {
        return DB::table('organization_role_assignments as assignment')
            ->join('organization_business_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('organization_role_permissions as action', 'action.role_id', '=', 'role.id')
            ->where('assignment.user_id', $user)->where('role.governed', false)
            ->select('role.business_id', 'action.action')->union(EntityRoleAccess::permissionsFor($user));
    }

    public static function businessKind(string $tenant, string $business): string
    {
        $kind = DB::table('customer_tenants as tenant')->join('business_identities as business', 'business.parent_id', '=', 'tenant.organization_id')
            ->where('tenant.id', $tenant)->where('business.id', $business)->whereIn('business.kind', ['Shop', 'Distributor'])
            ->value('business.kind');
        abort_if($kind === null, 404);

        return $kind;
    }
}
