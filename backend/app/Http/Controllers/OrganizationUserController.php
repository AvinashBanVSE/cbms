<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
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
    private const MODES = ['temporary_password', 'otp', 'both'];

    private const MAPPING_ACTIONS = [
        'Shop' => ['mapping.initiate', 'mapping.confirm', 'mapping.end'],
        'Distributor' => ['mapping.verify', 'mapping.end'],
    ];

    public function __construct(private readonly MainPortalAccess $access) {}

    public function configure(Request $request, string $tenant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        abort_unless(DB::table('customer_tenants')->where('id', $tenant)->exists(), 404);
        $input = $request->validate(['mode' => ['required', Rule::in(self::MODES)]]);
        DB::transaction(function () use ($tenant, $input): void {
            DB::table('organization_credential_policies')->upsert([
                'tenant_id' => $tenant, 'mode' => $input['mode'],
            ], ['tenant_id'], ['mode']);
            $this->record($tenant, 'organization_user.policy_changed');
        });

        return response()->json($input);
    }

    public function store(Request $request, string $tenant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $organization = DB::table('customer_tenants')->where('id', $tenant)->value('organization_id');
        abort_if($organization === null, 404);
        $input = $request->validate([
            'email' => 'required|email|max:254',
            'business_id' => 'required|uuid',
            'actions' => 'required|array|list|max:3',
            'actions.*' => 'required|string|distinct',
        ]);
        $business = DB::table('business_identities')->where('id', $input['business_id'])->where('parent_id', $organization)->value('kind');
        if (! isset(self::MAPPING_ACTIONS[$business]) || array_diff($input['actions'], self::MAPPING_ACTIONS[$business])) {
            throw ValidationException::withMessages(['actions' => 'Select permitted actions for a Shop or Distributor in this Organization.']);
        }
        $mode = DB::table('organization_credential_policies')->where('tenant_id', $tenant)->value('mode');
        abort_unless(in_array($mode, self::MODES, true), 409, 'Configure this Organization’s credential setup policy first.');
        $this->requireDeliverableMailer();

        $id = Str::uuid7()->toString();
        $code = DB::transaction(function () use ($id, $input, $tenant, $mode): string {
            // Serializes allocation against other registrations, including a concurrent retry.
            DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first();
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
        });
        $sent = $this->issue($tenant, $code);

        return response()->json(['user_code' => $code, 'delivery' => $sent ? 'sent' : 'failed'], 201);
    }

    public function reissue(Request $request, string $tenant): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/']);
        $this->requireDeliverableMailer();
        $sent = $this->issue($tenant, $input['code']);

        return response()->json(['delivery' => $sent ? 'sent' : 'failed']);
    }

    private function requireDeliverableMailer(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        abort_if(! is_string($transport) || in_array($transport, ['log', 'array', 'failover', 'roundrobin'], true), 503);
    }

    private function issue(string $tenant, string $code): bool
    {
        $credentials = DB::transaction(function () use ($tenant, $code): array {
            $account = DB::table('customer_accounts')->join('users', 'users.id', '=', 'customer_accounts.user_id')
                ->where('tenant_id', $tenant)->where('state', 'Pending Verification')->where('code', $code)
                ->select('users.id', 'users.email')->lockForUpdate()->first();
            abort_if($account === null, 404);
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
