<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class MainPortalAccess
{
    public function requirePermission(string $permission): void
    {
        abort_unless(Auth::check(), 401);

        abort_unless($this->allows((string) Auth::id(), $permission), 403);
    }

    public function allows(string $user, string $permission): bool
    {
        return $this->grantsFor($user)->where('permission', $permission)->exists();
    }

    public function grantsFor(string $user): Builder
    {
        $roles = DB::table('users as subject')->crossJoinSub(EntityRoleAccess::permissionsFor($user), 'roles')
            ->where('subject.id', $user)->select('subject.id as user_id', 'roles.business_id', 'roles.action as permission');
        $grants = DB::table('portal_grants')->where('user_id', $user)->select('user_id', 'business_id', 'permission')->union($roles);

        return DB::query()->fromSub($grants, 'portal_grants')
            ->join('portal_installation as installation', 'installation.portal_id', '=', 'portal_grants.business_id')
            ->where('installation.id', 1)->where('installation.stage', 'ready')->where('portal_grants.user_id', $user)
            ->whereIn('portal_grants.permission', array_keys(PermissionCatalogue::mainPortal()))
            ->whereNotExists(fn (Builder $accounts) => $accounts->selectRaw('1')->from('provider_accounts')->where('user_id', $user))
            ->whereNotExists(fn (Builder $accounts) => $accounts->selectRaw('1')->from('customer_accounts')->where('user_id', $user));
    }
}
