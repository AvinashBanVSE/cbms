<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationMfaController
{
    public function show(string $tenant, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        abort_unless(DB::table('customer_tenants')->where('id', $tenant)->exists(), 404);

        return response()->json(['required' => DB::table('organization_mfa_policies')->where('tenant_id', $tenant)->exists()])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $tenant, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $data = $request->validate(['required' => 'required|boolean', 'reason' => 'required|string|max:500']);
        $required = (bool) $data['required'];
        DB::transaction(function () use ($tenant, $required, $data): void {
            abort_if(DB::table('customer_tenants')->where('id', $tenant)->lockForUpdate()->first(['id']) === null, 404);
            $policy = DB::table('organization_mfa_policies')->where('tenant_id', $tenant);
            $before = $policy->exists();
            if ($before === $required) {
                return;
            }
            if ($required) {
                DB::table('organization_mfa_policies')->insert(['tenant_id' => $tenant]);
            } else {
                $policy->delete();
            }
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => Auth::id(), 'subject_id' => $tenant,
                'action' => 'credential_policy.organization_mfa_changed', 'recorded_at' => now(),
                'details' => json_encode(['required' => $required, 'reason' => $data['reason']], JSON_THROW_ON_ERROR),
            ]);
        }, attempts: 3);

        return response()->json(['required' => $required])->header('Cache-Control', 'no-store');
    }
}
