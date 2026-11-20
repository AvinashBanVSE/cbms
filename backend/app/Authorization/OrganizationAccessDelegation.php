<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class OrganizationAccessDelegation
{
    public function activeAssignments(string $recipient): Builder
    {
        return DB::table('organization_access_delegations as grant')
            ->join('customer_accounts as recipient', 'recipient.user_id', '=', 'grant.recipient_id')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'grant.tenant_id')
            ->join('business_identities as business', 'business.id', '=', 'grant.business_id')
            ->join('portal_installation as installation', 'installation.stage', '=', DB::raw("'ready'"))
            ->join('portal_grants as authority', function ($join): void {
                $join->on('authority.user_id', '=', 'grant.granted_by')
                    ->on('authority.business_id', '=', 'installation.portal_id')
                    ->where('authority.permission', 'organization_users.manage');
            })
            ->join('customer_business_associations as association', function ($join): void {
                $join->on('association.user_id', '=', 'grant.recipient_id')->on('association.business_id', '=', 'grant.business_id')
                    ->where('association.active', true);
            })
            ->join('customer_business_scopes as scope', function ($join): void {
                $join->on('scope.user_id', '=', 'grant.recipient_id')->on('scope.business_id', '=', 'grant.business_id');
            })
            ->where('installation.id', 1)->where('recipient.state', 'Active')
            ->where('grant.recipient_id', $recipient)
            ->whereColumn('recipient.tenant_id', 'tenant.id')->whereColumn('business.parent_id', 'tenant.organization_id')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('customer_accounts as issuer')->whereColumn('issuer.user_id', 'grant.granted_by');
            })
            ->whereNull('grant.revoked_at')->where('grant.starts_at', '<=', now())->where('grant.ends_at', '>', now());
    }

    public function requireFor(string $tenant, string $business, array $needed = [], bool $lock = false): ?string
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        if (! DB::table('customer_accounts')->where('user_id', $user)->exists()) {
            app(MainPortalAccess::class)->requirePermission('organization_users.manage');

            return null;
        }

        $query = $this->activeAssignments((string) $user)->where('grant.tenant_id', $tenant)->where('grant.business_id', $business)
            ->orderBy('grant.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        foreach ($query->get(['grant.id', 'grant.allowed_actions']) as $grant) {
            if (array_diff($needed, json_decode($grant->allowed_actions, true, flags: JSON_THROW_ON_ERROR)) === []) {
                return $grant->id;
            }
        }
        abort(403);
    }
}
