<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\SetupDelivery;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationOnboardingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AccountRecoveryController
{
    public function organization(Request $request, string $tenant, OrganizationOnboardingAccess $access): JsonResponse
    {
        $access->requireFor($tenant, permission: OrganizationOnboardingAccess::RECOVERY);

        return $this->issue($request, $tenant, false, fn (): bool => $access->requireFor($tenant, lock: true, permission: OrganizationOnboardingAccess::RECOVERY));
    }

    public function provider(Request $request, string $provider, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('provider_users.manage');

        return $this->issue($request, $provider, true, function () use ($access): bool {
            $access->requirePermission('provider_users.manage');

            return true;
        });
    }

    private function issue(Request $request, string $scope, bool $provider, callable $authorize): JsonResponse
    {
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'reason' => 'required|string|max:500']);
        SetupDelivery::requireMailer();
        $accounts = $provider ? 'provider_accounts' : 'customer_accounts';
        $setups = $provider ? 'provider_user_setups' : 'organization_user_setups';
        $scopeColumn = $provider ? 'provider_id' : 'tenant_id';
        [$email, $secret] = DB::transaction(function () use ($scope, $provider, $authorize, $input, $accounts, $setups, $scopeColumn): array {
            $main = $authorize();
            $query = DB::table($accounts.' as account')->join('users', 'users.id', '=', 'account.user_id')
                ->where('account.'.$scopeColumn, $scope)->where('account.state', 'Active')->where('users.code', $input['code']);
            if ($provider) {
                $query->join('business_identities as provider', 'provider.id', '=', 'account.provider_id')
                    ->where('provider.kind', 'Sub-Portal')->where('provider.operational_state', 'Active');
            }
            $target = $query->lockForUpdate()->first(['account.user_id', 'users.email', 'users.password']);
            abort_if($target === null, 404);
            if (! $main) {
                abort_if($target->user_id === Auth::id() || DB::table('customer_permissions')->where('user_id', $target->user_id)
                    ->whereIn('action', [OrganizationOnboardingAccess::PERMISSION, OrganizationOnboardingAccess::RECOVERY])->exists(), 403);
            }

            $previous = DB::table($setups)->where('user_id', $target->user_id)->lockForUpdate()->first();
            $recent = $previous?->last_issued_at !== null && now()->subHour()->lt($previous->last_issued_at);
            abort_if($recent && ($previous->issue_count >= 4 || now()->subMinute()->lt($previous->last_issued_at)), 429);
            $secret = Str::random(32);
            DB::table($setups)->upsert([
                'user_id' => $target->user_id, 'purpose' => 'recovery', 'mode' => 'temporary_password',
                'issue_count' => $recent ? $previous->issue_count + 1 : 1, 'last_issued_at' => now(),
                'prior_password_hash' => $previous?->prior_password_hash ?? $target->password,
                'temporary_hash' => Hash::make($secret), 'temporary_expires_at' => now()->addMinutes(15),
                'otp_hash' => null, 'otp_expires_at' => null, 'session_digest' => null, 'session_expires_at' => null,
            ], ['user_id']);
            DB::table('users')->where('id', $target->user_id)->update(['password' => Hash::make(Str::random(64))]);
            DB::table($accounts)->where('user_id', $target->user_id)->increment('session_version');
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => Auth::id(), 'subject_id' => $target->user_id,
                'action' => 'account.password_recovery_started', 'recorded_at' => now(),
                'details' => json_encode([$scopeColumn => $scope, 'reason' => $input['reason']], JSON_THROW_ON_ERROR),
            ]);

            return [$target->email, $secret];
        }, attempts: 3);

        return response()->json(['delivery' => SetupDelivery::send($email, $input['code'], $secret, null) ? 'sent' : 'failed'])
            ->header('Cache-Control', 'no-store');
    }
}
