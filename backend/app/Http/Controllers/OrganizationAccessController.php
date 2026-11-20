<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MappingPermissions;
use App\Authorization\OrganizationAccessDelegation;
use App\Authorization\OrganizationBusinessRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationAccessController
{
    public function __construct(private readonly OrganizationAccessDelegation $access) {}

    public function show(string $tenant, string $code, string $business): JsonResponse
    {
        $delegation = $this->access->requireFor($tenant, $business);
        $binding = $this->binding($tenant, $code, $business);

        abort_if($delegation !== null && $binding['user_id'] === Auth::id(), 403);

        return response()->json($this->snapshot($binding))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $tenant, string $code, string $business): JsonResponse
    {
        $this->access->requireFor($tenant, $business);
        $input = $request->validate([
            'associated' => 'required|boolean',
            'in_scope' => 'required|boolean',
            'actions' => 'present|array|list|max:'.MappingPermissions::MAX_ACTIONS,
            'actions.*' => 'required|string|distinct',
        ]);

        return DB::transaction(function () use ($input, $tenant, $code, $business): JsonResponse {
            $binding = $this->binding($tenant, $code, $business);
            DB::table('customer_accounts')->where('user_id', $binding['user_id'])->lockForUpdate()->first();
            MappingPermissions::validate(DB::table('business_identities')->where('id', $business)->value('kind'), $input['actions']);

            $before = $this->snapshot($binding);
            $actions = $input['actions'];
            sort($actions, SORT_STRING);
            $after = ['associated' => (bool) $input['associated'], 'in_scope' => (bool) $input['in_scope'], 'actions' => $actions];
            $needed = ($before['associated'] !== $after['associated'] || $before['in_scope'] !== $after['in_scope'])
                ? array_merge($before['actions'], $after['actions'], OrganizationBusinessRoles::permissionsFor($binding['user_id'])
                    ->where('role.business_id', $business)->pluck('action.action')->all())
                : array_merge(array_diff($before['actions'], $actions), array_diff($actions, $before['actions']));
            $delegation = $this->access->requireFor($tenant, $business, $needed, lock: true);
            if ($delegation !== null) {
                abort_if($binding['user_id'] === Auth::id(), 403);
                abort_if(DB::table('organization_access_delegations')->where('recipient_id', $binding['user_id'])
                    ->where('business_id', $business)->whereNull('revoked_at')->where('ends_at', '>', now())->exists(), 403);
            }
            if ($before === $after) {
                return response()->json($after);
            }

            DB::table('customer_business_associations')->upsert([
                ...$binding, 'active' => $after['associated'],
            ], ['user_id', 'business_id'], ['active']);
            if ($after['in_scope']) {
                DB::table('customer_business_scopes')->insertOrIgnore($binding);
            } else {
                DB::table('customer_business_scopes')->where($binding)->delete();
            }
            DB::table('customer_permissions')->where($binding)->delete();
            foreach ($actions as $action) {
                DB::table('customer_permissions')->insert([...$binding, 'action' => $action]);
            }
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'organization_user.access_changed',
                'actor_id' => Auth::id(), 'subject_id' => $binding['user_id'], 'recorded_at' => now(),
                'details' => json_encode([
                    'tenant_id' => $tenant, 'business_id' => $business, 'delegation_id' => $delegation, 'before' => $before, 'after' => $after,
                ], JSON_THROW_ON_ERROR),
            ]);

            return response()->json($after);
        }, attempts: 3);
    }

    private function binding(string $tenant, string $code, string $business): array
    {
        $user = DB::table('customer_accounts as account')
            ->join('users', 'users.id', '=', 'account.user_id')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('business_identities as business', 'business.parent_id', '=', 'tenant.organization_id')
            ->where('tenant.id', $tenant)->where('users.code', $code)->where('business.id', $business)
            ->whereIn('business.kind', ['Shop', 'Distributor'])->value('account.user_id');
        abort_if($user === null, 404);

        return ['user_id' => $user, 'business_id' => $business];
    }

    private function snapshot(array $binding): array
    {
        return [
            'associated' => (bool) DB::table('customer_business_associations')->where($binding)->value('active'),
            'in_scope' => DB::table('customer_business_scopes')->where($binding)->exists(),
            'actions' => DB::table('customer_permissions')->where($binding)->orderBy('action')->pluck('action')->all(),
        ];
    }
}
