<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Authorization\MappingPermissions;
use App\Authorization\OrganizationAccessDelegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrganizationDelegationController
{
    public function __construct(
        private readonly MainPortalAccess $access,
        private readonly OrganizationAccessDelegation $delegation,
    ) {}

    public function active(): JsonResponse
    {
        $user = Auth::id();
        abort_if($user === null, 401);

        return response()->json($this->delegation->summariesFor((string) $user))
            ->header('Cache-Control', 'no-store');
    }

    public function grant(Request $request, string $tenant, string $business): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $data = $request->validate([
            'code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/',
            'minutes' => 'required|integer|min:1|max:43200',
            'actions' => 'required|array|list|max:'.MappingPermissions::MAX_ACTIONS,
            'actions.*' => 'required|string|distinct',
        ]);

        return DB::transaction(function () use ($tenant, $business, $data): JsonResponse {
            $organization = DB::table('customer_tenants')->where('id', $tenant)->value('organization_id');
            abort_if($organization === null, 404);
            $kind = DB::table('business_identities')->where('id', $business)->where('parent_id', $organization)->value('kind');
            MappingPermissions::validate($kind, $data['actions']);
            $recipient = DB::table('customer_accounts as account')->join('users', 'users.id', '=', 'account.user_id')
                ->where('account.tenant_id', $tenant)->where('account.state', 'Active')->where('users.code', $data['code'])
                ->lockForUpdate()->value('account.user_id');
            $binding = ['user_id' => $recipient, 'business_id' => $business];
            if ($recipient === null
                || ! DB::table('customer_business_associations')->where($binding)->where('active', true)->exists()
                || ! DB::table('customer_business_scopes')->where($binding)->exists()) {
                throw ValidationException::withMessages(['code' => 'Select an active Organization user with association and scope for this business.']);
            }

            $id = Str::uuid7()->toString();
            $start = now();
            $end = $start->copy()->addMinutes((int) $data['minutes']);
            DB::table('organization_access_delegations')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'business_id' => $business,
                'recipient_id' => $recipient, 'granted_by' => Auth::id(),
                'allowed_actions' => json_encode($data['actions'], JSON_THROW_ON_ERROR),
                'starts_at' => $start, 'ends_at' => $end,
            ]);
            $this->audit($id, 'organization_access.delegated');

            return response()->json(['id' => $id, 'ends_at' => $end->toISOString()], 201);
        });
    }

    public function revoke(string $grant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        DB::transaction(function () use ($grant): void {
            $updated = DB::table('organization_access_delegations')->where('id', $grant)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            abort_unless($updated === 1, 404);
            $this->audit($grant, 'organization_access.delegation_revoked');
        });

        return response()->json(['status' => 'revoked']);
    }

    private function audit(string $id, string $action): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(),
            'subject_id' => $id, 'recorded_at' => now(),
        ]);
    }
}
