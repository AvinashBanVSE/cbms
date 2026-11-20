<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\PasswordHistory;
use App\Validation\PermanentPassword;
use Illuminate\Database\Query\Builder;
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

        $session = DB::transaction(function () use ($data): ?array {
            $setup = $this->pendingSetup(false)->join('users', 'users.id', '=', 'setup.user_id')
                ->where('users.code', $data['code'])->lockForUpdate()->first(['setup.*']);
            $provider = $setup === null;
            if ($provider) {
                $setup = $this->pendingSetup(true)->join('users', 'users.id', '=', 'setup.user_id')
                    ->where('users.code', $data['code'])->lockForUpdate()->first(['setup.*']);
            }
            abort_if($setup === null, 422);
            $issued = DB::table($provider ? 'provider_user_setups' : 'organization_user_setups')->where('user_id', $setup->user_id);
            $blockedUntil = $setup->verification_blocked_until;
            abort_if($blockedUntil !== null && now()->lt($blockedUntil), 429);
            $temporaryValid = $setup->mode === 'otp'
                || $this->validProof($data['temporary_password'] ?? '', $setup->temporary_hash, $setup->temporary_expires_at);
            $otpValid = $setup->mode === 'temporary_password'
                || $this->validProof($data['otp'] ?? '', $setup->otp_hash, $setup->otp_expires_at);
            if (! $temporaryValid || ! $otpValid) {
                if ($setup->temporary_hash !== null || $setup->otp_hash !== null) {
                    $failures = $blockedUntil !== null ? 1 : (int) $setup->verification_failures + 1;
                    $issued->update([
                        'verification_failures' => $failures,
                        'verification_blocked_until' => $failures >= 3 ? now()->addHour() : null,
                    ]);
                }

                return null;
            }

            $token = Str::random(64);
            $issued->update([
                'temporary_hash' => null, 'temporary_expires_at' => null,
                'otp_hash' => null, 'otp_expires_at' => null, 'session_digest' => hash('sha256', $token),
                'session_expires_at' => now()->addMinutes(5), 'verification_failures' => 0,
                'verification_blocked_until' => null,
            ]);

            return ['user_id' => $setup->user_id, 'token' => $token, 'provider' => $provider];
        }, attempts: 3);
        abort_if($session === null, 422);
        $request->session()->regenerate();
        $request->session()->put('organization_setup', $session);

        return response()->json(['stage' => 'set_password'])->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request): JsonResponse
    {
        abort_if(Auth::check(), 409);
        $session = $request->session()->get('organization_setup');
        abort_unless(is_array($session), 403);
        $provider = ($session['provider'] ?? false) === true;
        $proof = $this->pendingSetup($provider)
            ->where('setup.user_id', $session['user_id'])
            ->where('setup.session_digest', hash('sha256', $session['token']))
            ->where('setup.session_expires_at', '>', now());
        abort_unless($proof->exists(), 403);
        $input = $request->validate(['password' => [...PermanentPassword::rules(), 'confirmed']]);

        DB::transaction(function () use ($session, $input, $provider): void {
            $account = $this->pendingSetup($provider)->where('setup.user_id', $session['user_id'])
                ->lockForUpdate()->first(['setup.user_id', 'setup.purpose', 'setup.prior_password_hash']);
            abort_if($account === null, 403);
            $setup = DB::table($provider ? 'provider_user_setups' : 'organization_user_setups')->where('user_id', $account->user_id)
                ->where('session_digest', hash('sha256', $session['token']))->where('session_expires_at', '>', now());
            abort_unless($setup->delete() === 1, 403);
            if ($account->prior_password_hash !== null) {
                PasswordHistory::retire($account->user_id, $account->prior_password_hash, $input['password']);
            }
            DB::table('users')->where('id', $account->user_id)->update(['password' => Hash::make($input['password'])]);
            if ($account->purpose === 'setup') {
                DB::table($provider ? 'provider_accounts' : 'customer_accounts')->where('user_id', $account->user_id)->update(['state' => 'Active']);
            }
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(),
                'action' => $account->purpose === 'recovery' ? 'account.password_recovery_completed' : ($provider ? 'provider_user.activated' : 'organization_user.activated'),
                'actor_id' => $account->user_id, 'subject_id' => $account->user_id, 'recorded_at' => now(),
            ]);
        }, attempts: 3);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['stage' => 'ready']);
    }

    private function pendingSetup(bool $provider): Builder
    {
        $setups = $provider ? 'provider_user_setups' : 'organization_user_setups';
        $accounts = $provider ? 'provider_accounts' : 'customer_accounts';
        $query = DB::table($setups.' as setup')->join($accounts.' as account', 'account.user_id', '=', 'setup.user_id')
            ->where(function (Builder $eligible): void {
                $eligible->where(['setup.purpose' => 'setup', 'account.state' => 'Pending Verification'])
                    ->orWhere(['setup.purpose' => 'recovery', 'account.state' => 'Active']);
            });
        if ($provider) {
            $query->join('business_identities as provider', 'provider.id', '=', 'account.provider_id')
                ->where('provider.kind', 'Sub-Portal')->where('provider.operational_state', 'Active')
                ->whereNotExists(fn (Builder $customers) => $customers->selectRaw('1')->from('customer_accounts as customer')
                    ->whereColumn('customer.user_id', 'account.user_id'));
        }

        return $query;
    }

    private function validProof(string $secret, ?string $hash, ?string $expiry): bool
    {
        return $hash !== null && $expiry !== null && now()->lt($expiry) && Hash::check($secret, $hash);
    }
}
