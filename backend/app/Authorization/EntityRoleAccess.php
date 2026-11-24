<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EntityRoleAccess
{
    /** Current membership AND explicit scope; role assignment never supplies either. */
    public function eligible(string $user, string $business): bool
    {
        $entity = DB::table('business_identities')->where('id', $business)->first();
        if ($entity === null || $entity->operational_state !== 'Active') {
            return false;
        }
        $customer = DB::table('customer_accounts')->where('user_id', $user)->first();
        $provider = DB::table('provider_accounts')->where('user_id', $user)->first();
        if (in_array($entity->kind, ['Organization', 'Distributor', 'Shop'], true)) {
            if ($customer === null || $customer->state !== 'Active') {
                return false;
            }
            $organization = DB::table('customer_tenants')->where('id', $customer->tenant_id)->value('organization_id');

            return ($entity->kind === 'Organization' ? $entity->id === $organization : $entity->parent_id === $organization)
                && DB::table('business_identities')->where('id', $organization)->where('operational_state', 'Active')->exists()
                && DB::table('customer_business_associations')->where(['user_id' => $user, 'business_id' => $business, 'active' => true])->exists()
                && DB::table('customer_business_scopes')->where(['user_id' => $user, 'business_id' => $business])->exists();
        }
        if ($customer !== null || ! DB::table('entity_user_scopes')->where(['user_id' => $user, 'business_id' => $business])->exists()) {
            return false;
        }

        return match ($entity->kind) {
            'Sub-Portal' => $provider !== null && $provider->state === 'Active' && $provider->provider_id === $business,
            'Main Portal' => $provider === null && DB::table('users')->where('id', $user)->exists()
                && DB::table('portal_installation')->where(['id' => 1, 'portal_id' => $business, 'stage' => 'ready'])->exists(),
            default => false,
        };
    }

    /** Valid role grants only. Existing callers still apply their own resource boundaries. */
    public static function permissionsFor(string $user): Builder
    {
        return DB::table('organization_role_assignments as assignment')
            ->join('organization_business_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('organization_role_permissions as permission', 'permission.role_id', '=', 'role.id')
            ->join('permission_definitions as definition', 'definition.code', '=', 'permission.action')
            ->join('business_identities as business', 'business.id', '=', 'role.business_id')
            ->join('permission_applicability as applicability', function ($join): void {
                $join->on('applicability.permission_id', '=', 'definition.id')->on('applicability.entity_kind', '=', 'business.kind');
            })
            ->where('assignment.user_id', $user)->where('role.governed', true)->where('role.archived', false)
            ->where('definition.active', true)->where('business.operational_state', 'Active')
            ->where(function (Builder $context) use ($user): void {
                $context->where(function (Builder $customer) use ($user): void {
                    $customer->whereIn('business.kind', ['Organization', 'Distributor', 'Shop'])
                        ->whereExists(function (Builder $member) use ($user): void {
                            $member->selectRaw('1')->from('customer_accounts as role_account')
                                ->join('customer_tenants as role_tenant', 'role_tenant.id', '=', 'role_account.tenant_id')
                                ->join('business_identities as role_organization', 'role_organization.id', '=', 'role_tenant.organization_id')
                                ->join('customer_business_associations as role_association', 'role_association.user_id', '=', 'role_account.user_id')
                                ->join('customer_business_scopes as role_scope', function ($join): void {
                                    $join->on('role_scope.user_id', '=', 'role_account.user_id')->on('role_scope.business_id', '=', 'role_association.business_id');
                                })
                                ->where('role_account.user_id', $user)->where('role_account.state', 'Active')
                                ->where('role_organization.operational_state', 'Active')->where('role_association.active', true)
                                ->whereColumn('role_association.business_id', 'business.id')
                                ->where(function (Builder $boundary): void {
                                    $boundary->where(fn (Builder $organization) => $organization->where('business.kind', 'Organization')->whereColumn('business.id', 'role_tenant.organization_id'))
                                        ->orWhere(fn (Builder $child) => $child->whereIn('business.kind', ['Shop', 'Distributor'])->whereColumn('business.parent_id', 'role_tenant.organization_id'));
                                });
                        });
                })->orWhere(function (Builder $platform) use ($user): void {
                    $platform->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('customer_accounts')->where('user_id', $user))
                        ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('entity_user_scopes')->where('user_id', $user)->whereColumn('business_id', 'business.id'))
                        ->where(function (Builder $kind) use ($user): void {
                            $kind->where(function (Builder $provider) use ($user): void {
                                $provider->where('business.kind', 'Sub-Portal')
                                    ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('provider_accounts')->where(['user_id' => $user, 'state' => 'Active'])->whereColumn('provider_id', 'business.id'));
                            })->orWhere(function (Builder $portal) use ($user): void {
                                $portal->where('business.kind', 'Main Portal')
                                    ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('provider_accounts')->where('user_id', $user))
                                    ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('portal_installation')->where(['id' => 1, 'stage' => 'ready'])->whereColumn('portal_id', 'business.id'));
                            });
                        });
                });
            })
            ->select('role.business_id', 'permission.action');
    }

    public function allows(string $user, string $business, string $action): bool
    {
        return self::permissionsFor($user)->where('role.business_id', $business)->where('permission.action', $action)->exists();
    }

    public function manages(string $user, string $business): bool
    {
        return $this->portal($user) || $this->allows($user, $business, 'entity_roles.manage');
    }

    public function portal(string $user): bool
    {
        // The established Main Portal administrative grant remains the migration authority.
        // Deliberately avoid MainPortalAccess here to prevent recursive role resolution.
        return DB::table('portal_grants as grant')
            ->join('portal_installation as installation', 'installation.portal_id', '=', 'grant.business_id')
            ->where(['grant.user_id' => $user, 'grant.permission' => 'organization_users.manage', 'installation.id' => 1, 'installation.stage' => 'ready'])
            ->whereIn('grant.permission', array_keys(PermissionCatalogue::mainPortal()))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('customer_accounts')->where('user_id', $user))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('provider_accounts')->where('user_id', $user))->exists();
    }

    public function canGrant(string $user, string $business, array $actions): bool
    {
        if ($this->portal($user)) {
            return true;
        }
        if (! $this->eligible($user, $business)) {
            return false;
        }
        $limits = DB::table('organization_role_assignments as assignment')
            ->join('organization_business_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('entity_role_grant_limits as limits', 'limits.role_id', '=', 'role.id')
            ->join('permission_definitions as definition', 'definition.code', '=', 'limits.action')
            ->where(['assignment.user_id' => $user, 'role.business_id' => $business, 'role.governed' => true, 'role.archived' => false])
            ->where('definition.active', true)->where('definition.assignable', true)->pluck('limits.action')->all();

        return array_diff($actions, $limits) === [];
    }

    public function canApprove(string $user, string $business, array $actions): bool
    {
        return ($this->portal($user) || $this->allows($user, $business, 'entity_roles.approve'))
            && $this->canGrant($user, $business, $actions);
    }
}
