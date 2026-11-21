<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientActivationTest extends TestCase
{
    use RefreshDatabase;

    /** §6D.7 / Stage 5 gate: same-tenant Desktop success and exhausted capacity. */
    public function test_desktop_activation_binds_tenant_and_enforces_capacity(): void
    {
        [$tenant, $admin] = $this->tenantAdmin();
        $this->seedDesktopLimit(1);

        $this->actingAs(User::findOrFail($admin));
        $code = $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")
            ->assertCreated()->json('code');
        $this->assertSame('DCA-', substr($code, 0, 4));

        $first = $this->postJson('/api/v1/clients/desktop/activate', [
            'code' => $code, 'tenant_id' => $tenant,
        ])->assertCreated()->json('activation_token');
        $this->assertNotSame('', $first);

        // Reusing the consumed code is refused.
        $this->postJson('/api/v1/clients/desktop/activate', [
            'code' => $code, 'tenant_id' => $tenant,
        ])->assertUnprocessable();

        // Second instance exhausts the governed capacity (1).
        $code2 = $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertCreated()->json('code');
        $this->postJson('/api/v1/clients/desktop/activate', ['code' => $code2, 'tenant_id' => $tenant])
            ->assertUnprocessable();

        $this->assertDatabaseCount('client_instances', 1);
        $this->assertDatabaseHas('portal_events', ['action' => 'client.desktop_code_issued', 'subject_id' => $tenant]);
    }

    /** Cross-tenant denial: a valid code from one Tenant cannot activate another. */
    public function test_desktop_code_cannot_activate_another_tenant(): void
    {
        [$tenant, $admin] = $this->tenantAdmin();
        [$other] = $this->tenantAdmin();
        $this->seedDesktopLimit(5);

        $this->actingAs(User::findOrFail($admin));
        $code = $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertCreated()->json('code');
        $this->postJson('/api/v1/clients/desktop/activate', ['code' => $code, 'tenant_id' => $other])
            ->assertUnprocessable();
        $this->assertDatabaseCount('client_instances', 0);
    }

    /** An invalid code is refused; the Tenant Owner/Admin workflow is explicitly permitted only. */
    public function test_desktop_code_workflow_requires_tenant_admin(): void
    {
        [$tenant, $admin] = $this->tenantAdmin();
        $this->seedDesktopLimit(5);
        $this->actingAs(User::findOrFail($admin));
        $code = $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertCreated()->json('code');

        // A code from Tenant A cannot be consumed against another Tenant.
        [$otherTenant] = $this->tenantAdmin();
        $this->postJson('/api/v1/clients/desktop/activate', ['code' => $code, 'tenant_id' => $otherTenant])
            ->assertUnprocessable();
        $this->assertDatabaseCount('client_instances', 0);

        // A user with no Tenant Admin permission is denied the governed workflow.
        $outsider = Str::uuid7()->toString();
        $outsiderCode = 'C'.random_int(1000, 9999);
        DB::table('users')->insert([
            'id' => $outsider, 'code' => $outsiderCode, 'email' => $outsiderCode.'@example.test',
            'password' => 'unused',
        ]);
        $this->actingAs(User::findOrFail($outsider));
        $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertForbidden();

        // The real Tenant Admin can still issue, and a fabricated code cannot activate.
        $this->actingAs(User::findOrFail($admin));
        $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertCreated();
        $this->postJson('/api/v1/clients/desktop/activate', ['code' => 'DCA-XXXXXX-0000', 'tenant_id' => $tenant])
            ->assertUnprocessable();
        $this->assertDatabaseCount('client_instances', 0);
    }

    /** Revocation invalidates an instance and appears on the authorized device list. */
    public function test_desktop_revocation_invalidates_instance(): void
    {
        [$tenant, $admin] = $this->tenantAdmin();
        $this->seedDesktopLimit(3);
        $this->actingAs(User::findOrFail($admin));
        $code = $this->postJson("/api/v1/tenants/{$tenant}/clients/desktop-codes")->assertCreated()->json('code');
        $instance = $this->postJson('/api/v1/clients/desktop/activate', [
            'code' => $code, 'tenant_id' => $tenant, 'device_label' => 'Front desk',
        ])->assertCreated()->json('instance_id');

        $devices = $this->getJson("/api/v1/tenants/{$tenant}/clients")->assertOk()->json('data');
        $this->assertCount(1, $devices);
        $this->assertSame('Active', $devices[0]['state']);

        $this->deleteJson("/api/v1/tenants/{$tenant}/clients/instances/{$instance}")->assertOk();
        $this->getJson("/api/v1/tenants/{$tenant}/clients")->assertOk()
            ->assertJsonPath('data.0.state', 'Revoked');
        $this->assertDatabaseHas('portal_events', ['subject_id' => $instance, 'action' => 'client.instance_revoked']);
        $this->deleteJson("/api/v1/tenants/{$tenant}/clients/instances/".Str::uuid7())->assertNotFound();
    }

    /** Mobile: single active instance, atomic replacement, and replay refusal after replacement. */
    public function test_mobile_replacement_deactivates_prior_and_refuses_replay(): void
    {
        [$tenant, $admin] = $this->tenantAdmin();
        $this->actingAs(User::findOrFail($admin));
        $first = $this->postJson('/api/v1/clients/mobile/activate', ['device_label' => 'Phone'])
            ->assertCreated()->json('activation_token');
        $second = $this->postJson('/api/v1/clients/mobile/activate', ['device_label' => 'Replacement'])
            ->assertCreated()->json('activation_token');
        $this->assertNotSame($first, $second);

        $this->assertDatabaseCount('client_instances', 2);
        $this->assertDatabaseHas('client_instances', ['user_id' => $admin, 'device_label' => 'Phone', 'state' => 'Revoked']);
        $this->assertDatabaseHas('client_instances', ['user_id' => $admin, 'device_label' => 'Replacement', 'state' => 'Active']);
        $this->assertDatabaseHas('portal_events', ['action' => 'client.mobile_activated']);

        // Revoking the active instance ends its access.
        $instance = $this->postJson('/api/v1/clients/mobile/activate', ['device_label' => 'Third'])
            ->assertCreated()->json('instance_id');
        $this->deleteJson("/api/v1/tenants/{$tenant}/clients/instances/{$instance}")->assertOk();
        $this->assertDatabaseHas('client_instances', ['id' => $instance, 'state' => 'Revoked']);
    }

    /** @return array{0: string, 1: string} tenant id and admin user id */
    private function tenantAdmin(): array
    {
        $main = DB::table('business_identities')->where('kind', 'Main Portal')->value('id');
        if ($main === null) {
            $main = $this->business('Main Portal');
        }
        $parent = $this->business('Organization');
        $tenant = Str::uuid7()->toString();
        $user = Str::uuid7()->toString();
        $code = 'C'.random_int(1000, 9999);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $parent, 'provider_id' => $main]);
        DB::table('users')->insert(['id' => $user, 'code' => $code, 'email' => $code.'@example.test', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $user, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_permissions')->insert(['user_id' => $user, 'business_id' => $parent, 'action' => 'clients.activate']);

        return [$tenant, $user];
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'professional_name' => $kind,
        ]);

        return $id;
    }

    private function seedDesktopLimit(int $limit): void
    {
        DB::table('commercial_usage_limits')->insert([
            'code' => 'desktop.instances', 'name' => 'Desktop instances', 'resource' => 'desktop_instances',
            'unit' => 'instance', 'aggregation' => 'distinct', 'period' => 'rolling',
            'scope' => 'tenant', 'limit' => $limit, 'warning_threshold' => $limit,
            'enforcement' => 'hard_block', 'overage' => 'none', 'reset' => 'none', 'carry_forward' => 0,
        ]);
    }
}
