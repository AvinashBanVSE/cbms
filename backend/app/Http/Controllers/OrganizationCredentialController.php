<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Validation\PermanentPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OrganizationCredentialController
{
    public function verify(Request $request): JsonResponse
    {
        abort_if(Auth::check(), 409);
        $data = $request->validate([
            'code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/',
            'temporary_password' => 'nullable|string|size:32',
            'otp' => 'nullable|string|regex:/^[0-9]{6}$/',
        ]);

        DB::transaction(function () use ($request, $data): void {
            $setup = DB::table('organization_user_setups as setup')
                ->join('customer_accounts as account', 'account.user_id', '=', 'setup.user_id')
                ->join('users', 'users.id', '=', 'setup.user_id')->where('users.code', $data['code'])
                ->where('account.state', 'Pending Verification')->select('setup.*')->lockForUpdate()->first();
            abort_if($setup === null, 422);
            if ($setup->mode !== 'otp') {
                $this->requireProof($data['temporary_password'] ?? '', $setup->temporary_hash, $setup->temporary_expires_at);
            }
            if ($setup->mode !== 'temporary_password') {
                $this->requireProof($data['otp'] ?? '', $setup->otp_hash, $setup->otp_expires_at);
            }

            $token = Str::random(64);
            DB::table('organization_user_setups')->where('user_id', $setup->user_id)->update([
                'temporary_hash' => null, 'temporary_expires_at' => null,
                'otp_hash' => null, 'otp_expires_at' => null, 'session_digest' => hash('sha256', $token),
                'session_expires_at' => now()->addMinutes(5),
            ]);
            $request->session()->regenerate();
            $request->session()->put('organization_setup', ['user_id' => $setup->user_id, 'token' => $token]);
        });

        return response()->json(['stage' => 'set_password'])->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request): JsonResponse
    {
        abort_if(Auth::check(), 409);
        $session = $request->session()->get('organization_setup');
        abort_unless(is_array($session), 403);
        $proof = DB::table('organization_user_setups as setup')
            ->join('customer_accounts as account', 'account.user_id', '=', 'setup.user_id')
            ->where('setup.user_id', $session['user_id'])
            ->where('account.state', 'Pending Verification')
            ->where('setup.session_digest', hash('sha256', $session['token']))
            ->where('setup.session_expires_at', '>', now());
        abort_unless($proof->exists(), 403);
        $input = $request->validate(['password' => [...PermanentPassword::rules(), 'confirmed']]);

        DB::transaction(function () use ($session, $input): void {
            $account = DB::table('customer_accounts')->where('user_id', $session['user_id'])
                ->where('state', 'Pending Verification')->lockForUpdate()->first();
            abort_if($account === null, 403);
            $setup = DB::table('organization_user_setups')->where('user_id', $account->user_id)
                ->where('session_digest', hash('sha256', $session['token']))->where('session_expires_at', '>', now());
            abort_unless($setup->delete() === 1, 403);
            DB::table('users')->where('id', $account->user_id)->update(['password' => Hash::make($input['password'])]);
            DB::table('customer_accounts')->where('user_id', $account->user_id)->update(['state' => 'Active']);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'organization_user.activated',
                'actor_id' => $account->user_id, 'subject_id' => $account->user_id, 'recorded_at' => now(),
            ]);
        });
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['stage' => 'ready']);
    }

    private function requireProof(string $secret, ?string $hash, ?string $expiry): void
    {
        abort_unless($hash !== null && $expiry !== null && now()->lt($expiry) && Hash::check($secret, $hash), 422);
    }
}
