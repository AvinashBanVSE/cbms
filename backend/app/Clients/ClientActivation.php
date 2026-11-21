<?php

declare(strict_types=1);

namespace App\Clients;

use App\Authorization\PermissionCatalogue;
use App\Commercial\UsageMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-party client activation (§6D.7). Client registration, user authentication, tenant
 * scope and action authorization stay separate: an activation confers no business permission
 * and a successful activation does not itself grant access — every subsequent operation still
 * validates the authenticated user's membership of the activated Tenant.
 */
final class ClientActivation
{
    /** The governed desktop-instance Usage Limit; publishing it is a governance decision. */
    private const DESKTOP_CAPACITY_LIMIT = 'desktop.instances';

    public function __construct(private readonly UsageMeasurement $usage) {}

    /**
     * Generate (or regenerate, superseding prior codes) a Desktop activation code for one
     * Tenant through the explicitly permitted Tenant Owner/Admin workflow. Only the digest
     * is stored; the code is displayed once.
     *
     * @return array{client_id: string, tenant_id: string, code: string}
     */
    public function issueDesktopCode(string $actor, string $tenant): array
    {
        $this->requireTenantAdmin($actor, $tenant);
        $client = $this->activeClient('desktop');
        abort_unless($client !== null, 404, 'The Desktop client is not registered.');
        abort_unless(DB::table('customer_tenants')->where('id', $tenant)->exists(), 404);

        $code = 'DCA-'.Str::upper(Str::random(6)).'-'.random_int(1000, 9999);
        DB::transaction(function () use ($actor, $tenant, $client, $code): void {
            $version = (int) DB::table('client_activation_codes')
                ->where(['client_id' => $client, 'scope' => 'desktop', 'tenant_id' => $tenant])
                ->lockForUpdate()->max('code_version');
            DB::table('client_activation_codes')->insert([
                'id' => Str::uuid7()->toString(), 'client_id' => $client, 'scope' => 'desktop',
                'tenant_id' => $tenant, 'code_digest' => Hash::make($code),
                'generated_by' => $actor, 'code_version' => $version + 1, 'generated_at' => now(),
            ]);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $tenant,
                'action' => 'client.desktop_code_issued', 'recorded_at' => now(),
                'details' => json_encode(['client_id' => $client], JSON_THROW_ON_ERROR),
            ]);
        });

        return ['client_id' => $client, 'tenant_id' => $tenant, 'code' => $code];
    }

    /**
     * Consume a Desktop code into one instance bound to the Tenant, enforcing the Tenant's
     * desktop-instance capacity atomically. Returns the one-time activation token.
     *
     * @return array{instance_id: string, tenant_id: string, activation_token: string}
     */
    public function consumeDesktopCode(string $code, string $tenant, ?string $deviceLabel): array
    {
        abort_unless($code !== '', 422, 'Provide the activation code.');
        $client = $this->activeClient('desktop');
        abort_unless($client !== null, 404, 'The Desktop client is not registered.');

        return DB::transaction(function () use ($code, $tenant, $deviceLabel, $client): array {
            // Serialize code consumption and capacity on the Tenant organization.
            $organization = DB::table('customer_tenants')->where('id', $tenant)
                ->lockForUpdate()->value('organization_id');
            abort_unless(is_string($organization), 404);
            $row = DB::table('client_activation_codes')->where([
                'client_id' => $client, 'scope' => 'desktop', 'tenant_id' => $tenant,
            ])->whereNull('consumed_at')->whereNull('revoked_at')->lockForUpdate()
                ->orderByDesc('code_version')->first();
            abort_unless($row !== null && Hash::check($code, $row->code_digest), 422,
                'The activation code is not valid.');

            // Capacity is measured across the Tenant via the governed desktop-instance limit.
            // With no limit published (a governance decision) there is no capacity, so
            // activation is denied — deny-by-default.
            $this->usage->record(self::DESKTOP_CAPACITY_LIMIT, $organization, 1);

            $token = 'cbm_desktop_'.Str::random(48);
            $id = Str::uuid7()->toString();
            DB::table('client_instances')->insert([
                'id' => $id, 'client_id' => $client, 'scope' => 'desktop', 'tenant_id' => $tenant,
                'user_id' => null, 'token_hash' => hash('sha256', $token), 'device_label' => $deviceLabel,
                'state' => 'Active', 'activated_by' => $row->generated_by, 'activated_at' => now(),
            ]);
            DB::table('client_activation_codes')->where('id', $row->id)->update([
                'state' => 'consumed', 'consumed_at' => now(), 'consumed_by' => $id,
            ]);

            return ['instance_id' => $id, 'tenant_id' => $tenant, 'activation_token' => $token];
        });
    }

    /**
     * Activate a Mobile instance for the authenticated user, who must be a customer account.
     * Exactly one active instance per user — the previous is deactivated atomically on
     * replacement, so a replayed token is refused.
     *
     * @return array{instance_id: string, activation_token: string}
     */
    public function activateMobile(string $actor, ?string $deviceLabel): array
    {
        $client = $this->activeClient('mobile');
        abort_unless($client !== null, 404, 'The Mobile client is not registered.');

        return DB::transaction(function () use ($actor, $deviceLabel, $client): array {
            $account = DB::table('customer_accounts as account')
                ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
                ->join('customer_permissions as permission', function ($join): void {
                    $join->on('permission.user_id', '=', 'account.user_id')
                        ->on('permission.business_id', '=', 'tenant.organization_id')
                        ->whereIn('permission.action', PermissionCatalogue::TENANT_ADMIN);
                })
                ->where('account.user_id', $actor)->where('account.state', 'Active')
                ->lockForUpdate()->first(['account.tenant_id']);
            abort_unless($account !== null, 403, 'The Mobile client may be activated only by an eligible customer account.');
            $tenant = $account->tenant_id;

            // Single active instance per user; deactivate the prior so its token replays nothing.
            DB::table('client_instances')->where([
                'client_id' => $client, 'scope' => 'mobile', 'user_id' => $actor,
            ])->lockForUpdate()->get();
            DB::table('client_instances')->where([
                'client_id' => $client, 'scope' => 'mobile', 'user_id' => $actor, 'state' => 'Active',
            ])->update(['state' => 'Revoked', 'revoked_at' => now()]);
            $token = 'cbm_mobile_'.Str::random(48);
            $id = Str::uuid7()->toString();
            DB::table('client_instances')->insert([
                'id' => $id, 'client_id' => $client, 'scope' => 'mobile', 'tenant_id' => $tenant,
                'user_id' => $actor, 'token_hash' => hash('sha256', $token), 'device_label' => $deviceLabel,
                'state' => 'Active', 'activated_by' => $actor, 'activated_at' => now(),
            ]);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $id,
                'action' => 'client.mobile_activated', 'recorded_at' => now(),
                'details' => json_encode(['client_id' => $client], JSON_THROW_ON_ERROR),
            ]);

            return ['instance_id' => $id, 'activation_token' => $token];
        });
    }

    /**
     * Resolve a presented activation token to a live instance, else refuse. A successful
     * activation grants nothing by itself — business access is still authorized separately.
     */
    public function resolve(string $bearer): ?object
    {
        if ($bearer === '') {
            return null;
        }
        $instance = DB::table('client_instances')->where('token_hash', hash('sha256', $bearer))->first();
        if ($instance === null || $instance->state !== 'Active') {
            return null;
        }
        if ($instance->scope === 'mobile') {
            $account = DB::table('customer_accounts')->where('user_id', $instance->user_id)->first();
            if ($account === null || $account->state !== 'Active') {
                return null;
            }
        }

        return $instance;
    }

    /** Authorized device list for a Tenant Owner/Admin, covering Desktop and Mobile. */
    public function devices(string $actor, string $tenant): array
    {
        $this->requireTenantAdmin($actor, $tenant);

        return DB::table('client_instances')->where('tenant_id', $tenant)
            ->orderByDesc('activated_at')->get([
                'id', 'client_id', 'scope', 'user_id', 'device_label', 'state', 'activated_at', 'revoked_at',
            ])->all();
    }

    /** Revoke an instance (Desktop or Mobile) and invalidate its access. */
    public function revoke(string $actor, string $tenant, string $instance): void
    {
        $this->requireTenantAdmin($actor, $tenant);
        DB::transaction(function () use ($actor, $instance, $tenant): void {
            $row = DB::table('client_instances')->where('id', $instance)->where('tenant_id', $tenant)
                ->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->state === 'Active') {
                DB::table('client_instances')->where('id', $instance)->update([
                    'state' => 'Revoked', 'revoked_at' => now(),
                ]);
                DB::table('portal_events')->insert([
                    'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $instance,
                    'action' => 'client.instance_revoked', 'recorded_at' => now(),
                    'details' => json_encode(['scope' => $row->scope], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    private function requireTenantAdmin(string $actor, string $tenant): void
    {
        $permitted = DB::table('customer_accounts as account')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->join('customer_permissions as permission', function ($join): void {
                $join->on('permission.user_id', '=', 'account.user_id')
                    ->on('permission.business_id', '=', 'tenant.organization_id')
                    ->whereIn('permission.action', PermissionCatalogue::TENANT_ADMIN);
            })
            ->where('account.user_id', $actor)->where('account.state', 'Active')
            ->where('account.tenant_id', $tenant)->exists();
        abort_unless($permitted, 403, 'This workflow is not permitted for this account.');
    }

    private function activeClient(string $kind): ?string
    {
        return DB::table('registered_clients')->where('kind', $kind)->where('state', 'Active')
            ->orderBy('id')->value('id');
    }
}
