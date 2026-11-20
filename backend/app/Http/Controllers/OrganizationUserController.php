<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Authorization\MappingPermissions;
use App\Authorization\OrganizationOnboardingAccess;
use App\Configuration\OrganizationCredentialPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class OrganizationUserController
{
    public function __construct(
        private readonly MainPortalAccess $access,
        private readonly OrganizationCredentialPolicy $policy,
        private readonly OrganizationOnboardingAccess $onboarding,
    ) {}

    public function effectivePolicy(string $tenant): JsonResponse
    {
        $this->onboarding->requireFor($tenant);

        return response()->json($this->policy->resolve($tenant))->header('Cache-Control', 'no-store');
    }

    public function configureProvider(Request $request, string $provider): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        abort_unless(DB::table('business_identities')->where('id', $provider)->whereIn('kind', ['Main Portal', 'Sub-Portal'])->exists(), 404);
        $input = $request->validate(['mode' => ['required', Rule::in(OrganizationCredentialPolicy::MODES)]]);
        DB::transaction(function () use ($provider, $input): void {
            DB::table('business_identities')->where('id', $provider)->lockForUpdate()->first();
            DB::table('provider_credential_defaults')->upsert([
                'provider_id' => $provider, 'mode' => $input['mode'],
            ], ['provider_id'], ['mode']);
            $this->record($provider, 'organization_user.provider_default_changed');
        });

        return response()->json($input);
    }

    public function configure(Request $request, string $tenant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        abort_unless(DB::table('customer_tenants')->where('id', $tenant)->exists(), 404);
        $input = $request->validate(['mode' => ['required', Rule::in([...OrganizationCredentialPolicy::MODES, 'inherit'])]]);
        DB::transaction(function () use ($tenant, $input): void {
            DB::table('customer_tenants')->where('id', $tenant)->lockForUpdate()->first();
            if ($input['mode'] === 'inherit') {
                DB::table('organization_credential_policies')->where('tenant_id', $tenant)->delete();
            } else {
                DB::table('organization_credential_policies')->upsert([
                    'tenant_id' => $tenant, 'mode' => $input['mode'],
                ], ['tenant_id'], ['mode']);
            }
            $this->record($tenant, 'organization_user.policy_changed');
        });

        return $this->effectivePolicy($tenant);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $this->onboarding->requireFor($tenant);
        $input = $request->validate([
            'email' => 'required|email|max:254',
            'business_id' => 'required|uuid',
            'actions' => 'required|array|list|max:'.MappingPermissions::MAX_ACTIONS,
            'actions.*' => 'required|string|distinct',
        ]);
        $id = Str::uuid7()->toString();
        $code = DB::transaction(function () use ($id, $input, $tenant): string {
            // Serializes allocation against other registrations, including a concurrent retry.
            DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first();
            $mainPortal = $this->onboarding->requireFor($tenant, lock: true);
            $organization = DB::table('customer_tenants')->where('id', $tenant)->lockForUpdate()->value('organization_id');
            abort_if($organization === null, 404);
            if ($input['business_id'] === $organization) {
                abort_unless($mainPortal, 403);
                abort_unless(DB::table('business_identities')->where(['id' => $organization, 'kind' => 'Organization'])
                    ->where('operational_state', '!=', 'Closed')->lockForUpdate()->first(['id']) !== null, 422);
                if ($input['actions'] !== [OrganizationOnboardingAccess::PERMISSION]) {
                    throw ValidationException::withMessages(['actions' => 'Select the Organization onboarding permission for an Organization-level account.']);
                }
            } else {
                $business = DB::table('business_identities')->where('id', $input['business_id'])
                    ->where('parent_id', $organization)->where('operational_state', '!=', 'Closed')->lockForUpdate()->value('kind');
                MappingPermissions::validate($business, $input['actions']);
            }
            $mode = $this->policy->resolve($tenant)['mode'];
            abort_if($mode === null, 409, 'Configure an applicable credential setup policy first.');
            $this->requireDeliverableMailer();
            $email = Str::lower($input['email']);
            if (DB::table('users')->join('customer_accounts', 'users.id', '=', 'customer_accounts.user_id')
                ->where('tenant_id', $tenant)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email is unavailable.']);
            }
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $code = chr(random_int(65, 90)).chr(random_int(65, 90)).sprintf('%04d', random_int(0, 9999));
                if (! DB::table('users')->where('code', $code)->exists()) {
                    break;
                }
            }
            abort_if($attempt === 50, 503);
            DB::table('users')->insert(['id' => $id, 'code' => $code, 'email' => $email, 'password' => Hash::make(Str::random(64))]);
            DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Pending Verification']);
            DB::table('organization_user_setups')->insert(['user_id' => $id, 'mode' => $mode]);
            $binding = ['user_id' => $id, 'business_id' => $input['business_id']];
            DB::table('customer_business_associations')->insert([...$binding, 'active' => true]);
            DB::table('customer_business_scopes')->insert($binding);
            foreach ($input['actions'] as $action) {
                DB::table('customer_permissions')->insert([...$binding, 'action' => $action]);
            }
            $this->record($id, 'organization_user.provisioned');

            return $code;
        }, attempts: 3);
        $sent = $this->issue($tenant, $code);

        return response()->json(['user_code' => $code, 'delivery' => $sent ? 'sent' : 'failed'], 201);
    }

    public function reissue(Request $request, string $tenant): JsonResponse
    {
        $this->onboarding->requireFor($tenant);
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/']);
        $this->requireDeliverableMailer();
        $sent = $this->issue($tenant, $input['code']);

        return response()->json(['delivery' => $sent ? 'sent' : 'failed']);
    }

    public function revokeOnboarding(Request $request, string $tenant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $data = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/']);
        DB::transaction(function () use ($tenant, $data): void {
            $context = DB::table('customer_accounts as account')
                ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
                ->join('users', 'users.id', '=', 'account.user_id')
                ->where('account.tenant_id', $tenant)->where('users.code', $data['code'])->lockForUpdate()
                ->first(['account.user_id', 'tenant.organization_id']);
            abort_if($context === null, 404);
            $removed = DB::table('customer_permissions')->where([
                'user_id' => $context->user_id, 'business_id' => $context->organization_id,
                'action' => OrganizationOnboardingAccess::PERMISSION,
            ])->delete();
            abort_unless($removed === 1, 404);
            $this->record($context->user_id, 'organization_user.onboarding_authority_revoked');
        }, attempts: 3);

        return response()->json(['status' => 'revoked']);
    }

    private function requireDeliverableMailer(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        abort_if(! is_string($transport) || in_array($transport, ['log', 'array', 'failover', 'roundrobin'], true), 503);
    }

    private function issue(string $tenant, string $code): bool
    {
        $credentials = DB::transaction(function () use ($tenant, $code): array {
            $mainPortal = $this->onboarding->requireFor($tenant, lock: true);
            $account = DB::table('customer_accounts')->join('users', 'users.id', '=', 'customer_accounts.user_id')
                ->where('tenant_id', $tenant)->where('state', 'Pending Verification')->where('code', $code)
                ->select('users.id', 'users.email')->lockForUpdate()->first();
            abort_if($account === null, 404);
            if (! $mainPortal) {
                abort_if(DB::table('customer_permissions')->where('user_id', $account->id)
                    ->where('action', OrganizationOnboardingAccess::PERMISSION)->exists(), 403);
            }
            $setup = DB::table('organization_user_setups')->where('user_id', $account->id);
            $previous = $setup->first();
            abort_if($previous === null, 409);
            abort_if($previous->issue_count >= 4 || ($previous->last_issued_at !== null && now()->subMinute()->lt($previous->last_issued_at)), 429);
            $mode = $previous->mode;
            $temporary = $mode !== 'otp' ? Str::random(32) : null;
            $otp = $mode !== 'temporary_password' ? sprintf('%06d', random_int(0, 999999)) : null;
            $setup->update([
                'issue_count' => $previous->issue_count + 1,
                'last_issued_at' => now(),
                'temporary_hash' => $temporary ? Hash::make($temporary) : null,
                'otp_hash' => $otp !== null ? Hash::make($otp) : null,
                'temporary_expires_at' => $temporary ? now()->addMinutes(15) : null,
                'otp_expires_at' => $otp !== null ? now()->addMinutes(5) : null,
                'session_digest' => null, 'session_expires_at' => null,
            ]);
            $this->record($account->id, 'organization_user.credentials_reissued');

            return [$account->email, $temporary, $otp];
        });
        [$email, $temporary, $otp] = $credentials;
        $body = ["Your CBM User Code is {$code}. Open the CBM Portal and select Set up account."];
        if ($temporary !== null) {
            $body[] = "Temporary password: {$temporary}";
        }
        if ($otp !== null) {
            $body[] = "One-time code: {$otp}";
        }

        try {
            return Mail::raw(implode("\n", $body), fn ($message) => $message->to($email)->subject('CBM account setup')) !== null;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    private function record(string $subject, string $action): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'subject_id' => $subject,
            'actor_id' => Auth::id(), 'recorded_at' => now(),
        ]);
    }
}
