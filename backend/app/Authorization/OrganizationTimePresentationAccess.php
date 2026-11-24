<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class OrganizationTimePresentationAccess
{
    public const PERMISSIONS = [
        'clock' => 'business_time_presentation.update',
        'date_format' => 'business_calendar_presentation.date_format.update',
        'working_days' => 'business_calendar_presentation.working_days.update',
        'week_start' => 'business_calendar_presentation.week_start.update',
        'negative_amount' => 'business_number_presentation.negative_amount.update',
    ];

    public function contextFor(string $user, bool $lock = false, string $setting = 'clock'): ?object
    {
        $assigned = $this->assignments($user, [self::PERMISSIONS[$setting]]);
        if ($lock) {
            $assigned->lockForUpdate();
        }

        return $assigned->first(['business.id', 'business.professional_name']);
    }

    public function workspaceContext(string $user): ?array
    {
        $rows = $this->assignments($user, array_values(self::PERMISSIONS))
            ->get(['business.id', 'business.professional_name', 'permission.action']);
        $business = $rows->first();
        if ($business === null) {
            return null;
        }

        return [
            'id' => $business->id, 'professional_name' => $business->professional_name,
            'settings' => array_keys(array_intersect(self::PERMISSIONS, $rows->pluck('action')->all())),
        ];
    }

    private function assignments(string $user, array $permissions): Builder
    {
        return DB::table('customer_accounts as account')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('business_identities as business', 'business.id', '=', 'tenant.organization_id')
            ->join('customer_business_associations as association', function ($join): void {
                $join->on('association.user_id', '=', 'account.user_id')->on('association.business_id', '=', 'business.id')
                    ->where('association.active', true);
            })->join('customer_business_scopes as scope', function ($join): void {
                $join->on('scope.user_id', '=', 'account.user_id')->on('scope.business_id', '=', 'business.id');
            })->joinSub(CustomerPermissionGrants::forUser($user), 'permission', function ($join) use ($permissions): void {
                $join->on('permission.user_id', '=', 'account.user_id')->on('permission.business_id', '=', 'business.id')
                    ->whereIn('permission.action', $permissions);
            })
            ->whereIn('permission.action', PermissionCatalogue::actions('Organization'))
            ->where(['account.user_id' => $user, 'account.state' => 'Active', 'business.kind' => 'Organization', 'business.operational_state' => 'Active']);
    }

    public function requireOrganization(bool $lock = false, string $setting = 'clock'): string
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        $organization = $this->contextFor((string) $user, $lock, $setting);
        abort_if($organization === null, 403);

        return $organization->id;
    }
}
