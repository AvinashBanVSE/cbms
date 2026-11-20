<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\PasswordHistory;
use App\Accounts\SetupOtpPolicy;
use App\Authorization\MainPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CredentialSettingsController
{
    public function show(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');

        return response()->json(['password_history_count' => PasswordHistory::depth()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $input = $request->validate([
            'password_history_count' => 'required|integer|between:'.PasswordHistory::MIN_DEPTH.','.PasswordHistory::MAX_DEPTH,
        ]);
        $count = (int) $input['password_history_count'];
        DB::transaction(function () use ($access, $count): void {
            $portal = DB::table('portal_installation')->where('id', 1)->lockForUpdate()->value('portal_id');
            $access->requirePermission('organization_users.manage');
            $stored = DB::table('portal_credential_settings')->where('id', 1)->value('password_history_count');
            if ($stored !== null && (int) $stored === $count) {
                return;
            }
            $prior = PasswordHistory::depth();
            DB::table('portal_credential_settings')->upsert([
                'id' => 1, 'password_history_count' => $count,
            ], ['id'], ['password_history_count']);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'credential_policy.password_history_changed',
                'actor_id' => Auth::id(), 'subject_id' => $portal, 'recorded_at' => now(),
                'details' => json_encode(['previous_count' => $prior, 'new_count' => $count], JSON_THROW_ON_ERROR),
            ]);
        }, attempts: 3);

        return response()->json(['password_history_count' => $count])->header('Cache-Control', 'no-store');
    }

    public function showOtp(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');

        return response()->json(['validity_minutes' => SetupOtpPolicy::minutes()])->header('Cache-Control', 'no-store');
    }

    public function updateOtp(Request $request, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $input = $request->validate([
            'validity_minutes' => 'required|integer|between:'.SetupOtpPolicy::MIN_MINUTES.','.SetupOtpPolicy::MAX_MINUTES,
        ]);
        $minutes = (int) $input['validity_minutes'];
        DB::transaction(function () use ($access, $minutes): void {
            $portal = DB::table('portal_installation')->where('id', 1)->lockForUpdate()->value('portal_id');
            $access->requirePermission('organization_users.manage');
            $prior = SetupOtpPolicy::minutes();
            if ($prior === $minutes) {
                return;
            }

            DB::table('portal_setup_otp_policy')->upsert([
                'id' => 1, 'validity_minutes' => $minutes,
            ], ['id'], ['validity_minutes']);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'credential_policy.setup_otp_validity_changed',
                'actor_id' => Auth::id(), 'subject_id' => $portal, 'recorded_at' => now(),
                'details' => json_encode(['previous_minutes' => $prior, 'new_minutes' => $minutes], JSON_THROW_ON_ERROR),
            ]);
        }, attempts: 3);

        return response()->json(['validity_minutes' => $minutes])->header('Cache-Control', 'no-store');
    }
}
