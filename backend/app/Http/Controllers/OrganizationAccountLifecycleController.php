<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\Authenticator;
use App\Authorization\MainPortalAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OrganizationAccountLifecycleController
{
    private const TRANSITIONS = [
        'Pending Verification' => ['Terminated'],
        'Active' => ['Suspended', 'Deactivated', 'Terminated'],
        'Locked' => ['Suspended', 'Deactivated', 'Terminated'],
        'Suspended' => ['Active', 'Deactivated', 'Terminated'],
        'Deactivated' => ['Active', 'Terminated'],
        'Terminated' => [],
    ];

    public function __construct(private readonly MainPortalAccess $access) {}

    public function show(string $tenant, string $code): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $account = $this->account($tenant, $code)->first();
        abort_if($account === null, 404);

        return response()->json($this->representation($account->state))->header('Cache-Control', 'no-store');
    }

    public function transition(Request $request, string $tenant, string $code): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $input = $request->validate([
            'state' => 'required|in:Active,Suspended,Deactivated,Terminated',
            'expected_state' => 'required|string|max:40',
            'reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($tenant, $code, $input): JsonResponse {
            $account = $this->account($tenant, $code)->lockForUpdate()->first();
            abort_if($account === null, 404);
            $next = $input['state'];
            abort_unless($account->state === $input['expected_state'] && in_array($next, self::TRANSITIONS[$account->state] ?? [], true), 409);
            $id = $account->user_id;
            DB::table('customer_accounts')->where('user_id', $id)->update([
                'state' => $next, 'session_version' => (int) $account->session_version + 1,
            ]);
            if (in_array($next, ['Suspended', 'Deactivated'], true)) {
                DB::table('organization_user_setups')->where(['user_id' => $id, 'purpose' => 'recovery'])->update([
                    'temporary_hash' => null, 'temporary_expires_at' => null, 'session_digest' => null, 'session_expires_at' => null,
                ]);
            }
            if ($next === 'Terminated') {
                DB::table('users')->where('id', $id)->update(['password' => Hash::make(Str::random(64))]);
                Authenticator::revokeForTermination($id);
                DB::table('organization_user_setups')->where('user_id', $id)->delete();
                DB::table('customer_business_associations')->where('user_id', $id)->update(['active' => false]);
            }
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => Auth::id(), 'subject_id' => $id,
                'action' => 'organization_user.state_changed', 'recorded_at' => now(),
                'details' => json_encode([
                    'tenant_id' => $tenant, 'from' => $account->state, 'to' => $next, 'reason' => $input['reason'],
                ], JSON_THROW_ON_ERROR),
            ]);

            return response()->json($this->representation($next));
        }, attempts: 3);
    }

    private function account(string $tenant, string $code): Builder
    {
        return DB::table('customer_accounts as account')->join('users', 'users.id', '=', 'account.user_id')
            ->where('account.tenant_id', $tenant)->where('users.code', $code)->select('account.*');
    }

    private function representation(string $state): array
    {
        return ['state' => $state, 'allowed_transitions' => self::TRANSITIONS[$state] ?? []];
    }
}
