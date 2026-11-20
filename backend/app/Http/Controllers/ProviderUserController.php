<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\Authenticator;
use App\Accounts\SetupDelivery;
use App\Accounts\UserCode;
use App\Authorization\MainPortalAccess;
use App\Authorization\ProviderAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProviderUserController
{
    public function __construct(private readonly MainPortalAccess $portal, private readonly ProviderAccess $access) {}

    public function store(Request $request, string $provider): JsonResponse
    {
        $this->portal->requirePermission('provider_users.manage');
        $input = $request->validate(['email' => 'required|email|max:254', 'permission' => 'required|in:'.ProviderAccess::DIRECTORY]);
        SetupDelivery::requireMailer();
        $code = DB::transaction(function () use ($provider, $input): string {
            $code = UserCode::allocate();
            $this->requireActiveProvider($provider);
            $email = Str::lower($input['email']);
            if (DB::table('provider_accounts as account')->join('users', 'users.id', '=', 'account.user_id')
                ->where('account.provider_id', $provider)->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email is unavailable.']);
            }
            $user = Str::uuid7()->toString();
            DB::table('users')->insert(['id' => $user, 'code' => $code, 'email' => $email, 'password' => Hash::make(Str::random(64))]);
            DB::table('provider_accounts')->insert(['user_id' => $user, 'provider_id' => $provider, 'state' => 'Pending Verification']);
            DB::table('provider_account_permissions')->insert(['user_id' => $user, 'permission' => $input['permission']]);
            DB::table('provider_user_setups')->insert(['user_id' => $user, 'mode' => 'otp']);
            $this->record('provider_user.provisioned', $user, $provider);

            return $code;
        }, attempts: 3);

        return response()->json(['user_code' => $code, 'delivery' => $this->issue($provider, $code) ? 'sent' : 'failed'], 201);
    }

    public function reissue(Request $request, string $provider): JsonResponse
    {
        $this->portal->requirePermission('provider_users.manage');
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/']);
        SetupDelivery::requireMailer();

        return response()->json(['delivery' => $this->issue($provider, $input['code']) ? 'sent' : 'failed']);
    }

    public function revoke(Request $request, string $provider): JsonResponse
    {
        $this->portal->requirePermission('provider_users.manage');
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'reason' => 'required|string|max:500']);
        DB::transaction(function () use ($provider, $input): void {
            $account = $this->account($provider, $input['code'])->lockForUpdate()->first(['account.user_id', 'account.state']);
            abort_if($account === null, 404);
            abort_if($account->state === 'Terminated', 409);
            DB::table('provider_accounts')->where('user_id', $account->user_id)->update([
                'state' => 'Terminated', 'session_version' => DB::raw('session_version + 1'),
            ]);
            DB::table('provider_account_permissions')->where('user_id', $account->user_id)->delete();
            DB::table('provider_user_setups')->where('user_id', $account->user_id)->delete();
            DB::table('users')->where('id', $account->user_id)->update(['password' => Hash::make(Str::random(64))]);
            Authenticator::revokeForTermination($account->user_id);
            $this->record('provider_user.terminated', $account->user_id, $provider, ['reason' => $input['reason']]);
        }, attempts: 3);

        return response()->json(['state' => 'Terminated']);
    }

    public function organizations(): JsonResponse
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        $provider = $this->access->directoryFor((string) $user);
        abort_if($provider === null, 403);
        $organizations = DB::table('customer_tenants as tenant')
            ->join('business_identities as organization', 'organization.id', '=', 'tenant.organization_id')
            ->where('tenant.provider_id', $provider->id)
            ->whereIn('tenant.provider_id', $this->access->directoryScope((string) $user)->select('provider.id'))
            ->where('organization.kind', 'Organization')
            ->orderBy('tenant.id')->simplePaginate(30, [
                'tenant.id as tenant_id', 'organization.id', 'organization.professional_name', 'organization.operational_state',
            ]);

        return response()->json($organizations)->header('Cache-Control', 'no-store');
    }

    private function issue(string $provider, string $code): bool
    {
        [$email, $otp] = DB::transaction(function () use ($provider, $code): array {
            $this->portal->requirePermission('provider_users.manage');
            $this->requireActiveProvider($provider);
            $account = $this->account($provider, $code)->where('account.state', 'Pending Verification')
                ->lockForUpdate()->first(['account.user_id', 'users.email']);
            abort_if($account === null, 404);
            $setup = DB::table('provider_user_setups')->where('user_id', $account->user_id);
            $prior = $setup->lockForUpdate()->first();
            abort_if($prior === null, 409);
            abort_if($prior->issue_count >= 4 || ($prior->last_issued_at !== null && now()->subMinute()->lt($prior->last_issued_at)), 429);
            $otp = sprintf('%06d', random_int(0, 999999));
            $setup->update([
                'issue_count' => $prior->issue_count + 1, 'last_issued_at' => now(), 'otp_hash' => Hash::make($otp),
                'otp_expires_at' => now()->addMinutes(5), 'session_digest' => null, 'session_expires_at' => null,
            ]);
            $this->record('provider_user.setup_code_issued', $account->user_id, $provider);

            return [$account->email, $otp];
        }, attempts: 3);

        return SetupDelivery::send($email, $code, null, $otp);
    }

    private function requireActiveProvider(string $provider): void
    {
        abort_unless(DB::table('business_identities')->where(['id' => $provider, 'kind' => 'Sub-Portal', 'operational_state' => 'Active'])
            ->lockForUpdate()->first(['id']) !== null, 404);
    }

    private function account(string $provider, string $code): Builder
    {
        return DB::table('provider_accounts as account')->join('users', 'users.id', '=', 'account.user_id')
            ->where('account.provider_id', $provider)->where('users.code', $code);
    }

    private function record(string $action, string $user, string $provider, array $details = []): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(),
            'subject_id' => $user, 'recorded_at' => now(),
            'details' => json_encode(['provider_id' => $provider, ...$details], JSON_THROW_ON_ERROR),
        ]);
    }
}
