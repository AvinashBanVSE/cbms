<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class CustomerBusinessAccess
{
    public function requirePermission(string $business, string $kind, string $action): string
    {
        $user = Auth::id();
        abort_if($user === null, 401);

        $allowed = $this->activeAssignments((string) $user)
            ->where('entity.id', $business)->where('entity.kind', $kind)
            ->where('permission.action', $action)->exists();
        abort_unless($allowed, 403);

        return (string) $user;
    }

    public function mappingContexts(): array
    {
        $user = Auth::id();
        abort_if($user === null, 401);

        return $this->activeAssignments((string) $user)
            ->whereIn('entity.kind', ['Shop', 'Distributor'])
            ->whereIn('permission.action', ['mapping.initiate', 'mapping.verify', 'mapping.confirm', 'mapping.end'])
            ->select('entity.id', 'entity.kind', 'entity.professional_name', 'permission.action')
            ->orderBy('entity.id')->orderBy('permission.action')->get()->all();
    }

    private function activeAssignments(string $user): Builder
    {
        return DB::table('customer_accounts as account')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('customer_business_associations as association', 'association.user_id', '=', 'account.user_id')
            ->join('business_identities as entity', 'entity.id', '=', 'association.business_id')
            ->join('customer_business_scopes as scope', function ($join): void {
                $join->on('scope.user_id', '=', 'account.user_id')->on('scope.business_id', '=', 'entity.id');
            })
            ->joinSub(
                DB::table('customer_permissions')->where('user_id', $user)->select('business_id', 'action')
                    ->union(OrganizationBusinessRoles::permissionsFor($user)),
                'permission',
                'permission.business_id',
                '=',
                'entity.id',
            )
            ->whereColumn('entity.parent_id', 'tenant.organization_id')
            ->where('account.user_id', $user)
            ->where('account.state', 'Active')
            ->where('association.active', true);
    }
}
