<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class OrganizationOnboardingAccess
{
    public const PERMISSION = 'organization_users.provision';

    public const RECOVERY = 'organization_users.recover';

    public function __construct(private readonly MainPortalAccess $portal) {}

    /**
     * Returns whether the caller is acting under Main Portal authority.
     */
    public function requireFor(string $tenant, bool $lock = false, string $permission = self::PERMISSION): bool
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        if ($this->portal->allows((string) $user, 'organization_users.manage')) {
            return true;
        }
        $authorized = $this->assignments((string) $user, $permission)->where('tenant.id', $tenant);
        if ($lock) {
            $authorized->lockForUpdate();
        }
        abort_if($authorized->first(['tenant.id']) === null, 403);

        return false;
    }

    public function contextFor(string $user, string $permission = self::PERMISSION): ?object
    {
        return $this->assignments($user, $permission)->first(['tenant.id as tenant_id', 'organization.professional_name']);
    }

    private function assignments(string $user, string $permission): Builder
    {
        return DB::table('customer_accounts as account')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('business_identities as organization', 'organization.id', '=', 'tenant.organization_id')
            ->join('customer_business_associations as association', function ($join): void {
                $join->on('association.user_id', '=', 'account.user_id')->on('association.business_id', '=', 'organization.id')
                    ->where('association.active', true);
            })->join('customer_business_scopes as scope', function ($join): void {
                $join->on('scope.user_id', '=', 'account.user_id')->on('scope.business_id', '=', 'organization.id');
            })->joinSub(CustomerPermissionGrants::forUser($user), 'permission', function ($join) use ($permission): void {
                $join->on('permission.user_id', '=', 'account.user_id')->on('permission.business_id', '=', 'organization.id')
                    ->where('permission.action', $permission);
            })
            ->where('account.user_id', $user)->where('account.state', 'Active')
            ->whereIn('permission.action', PermissionCatalogue::actions('Organization'))
            ->where('organization.kind', 'Organization')->where('organization.operational_state', 'Active');
    }
}
