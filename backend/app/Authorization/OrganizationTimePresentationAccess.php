<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class OrganizationTimePresentationAccess
{
    public const PERMISSION = 'business_time_presentation.update';

    public function contextFor(string $user, bool $lock = false): ?object
    {
        $assigned = DB::table('customer_accounts as account')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('business_identities as business', 'business.id', '=', 'tenant.organization_id')
            ->join('customer_business_associations as association', function ($join): void {
                $join->on('association.user_id', '=', 'account.user_id')->on('association.business_id', '=', 'business.id')
                    ->where('association.active', true);
            })->join('customer_business_scopes as scope', function ($join): void {
                $join->on('scope.user_id', '=', 'account.user_id')->on('scope.business_id', '=', 'business.id');
            })->join('customer_permissions as permission', function ($join): void {
                $join->on('permission.user_id', '=', 'account.user_id')->on('permission.business_id', '=', 'business.id')
                    ->where('permission.action', self::PERMISSION);
            })
            ->where(['account.user_id' => $user, 'account.state' => 'Active', 'business.kind' => 'Organization', 'business.operational_state' => 'Active']);
        if ($lock) {
            $assigned->lockForUpdate();
        }

        return $assigned->first(['business.id', 'business.professional_name']);
    }

    public function requireOrganization(bool $lock = false): string
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        $organization = $this->contextFor((string) $user, $lock);
        abort_if($organization === null, 403);

        return $organization->id;
    }
}
