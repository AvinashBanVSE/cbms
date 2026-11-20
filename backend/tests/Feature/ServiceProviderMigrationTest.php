<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ServiceProviderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_is_revalidated_and_migration_preserves_tenant_bound_records(): void
    {
        $main = $this->business('Main Portal');
        $source = $this->business('Sub-Portal');
        $destination = $this->business('Sub-Portal');
        [$first, $organization, $shop] = $this->tenant($source);
        [$second] = $this->tenant($source);
        [$unrelated] = $this->tenant($main);
        $administrator = Str::uuid7()->toString();
        $operator = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $main, 'stage' => 'ready']);
        DB::table('users')->insert([
            ['id' => $administrator, 'code' => 'AA8811', 'email' => 'admin@example.test', 'password' => 'unused'],
            ['id' => $operator, 'code' => 'AA8812', 'email' => 'operator@example.test', 'password' => 'unused'],
        ]);
        DB::table('customer_accounts')->insert(['user_id' => $operator, 'tenant_id' => $first, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $operator, 'business_id' => $shop, 'active' => true]);
        DB::table('portal_grants')->insert(['business_id' => $main, 'user_id' => $administrator, 'permission' => 'service_provider.migrate']);
        DB::table('provider_credential_defaults')->insert([
            ['provider_id' => $source, 'mode' => 'otp'], ['provider_id' => $destination, 'mode' => 'both'], ['provider_id' => $main, 'mode' => 'otp'],
        ]);
        $scope = ['source_id' => $source, 'destination_id' => $destination];
        $preview = '/api/v1/provider-migrations/preview';
        $execute = '/api/v1/provider-migrations/execute';
        $this->postJson($preview, $scope)->assertUnauthorized();
        $this->actingAs(User::findOrFail($operator));
        $this->postJson($preview, $scope)->assertForbidden();
        $this->actingAs(User::findOrFail($administrator));
        $this->postJson($preview, [...$scope, 'source_id' => $main])->assertUnprocessable();
        $this->postJson($preview, [...$scope, 'destination_id' => $source])->assertUnprocessable();
        $this->postJson($preview, [...$scope, 'tenant_id' => $unrelated])->assertJsonPath('can_execute', false);
        $blocked = $this->postJson($preview, $scope)->assertOk()->assertJsonPath('can_execute', false)->assertJsonCount(2, 'tenants')->json();
        $this->postJson($execute, [...$scope, 'operation_id' => Str::uuid7()->toString(), 'fingerprint' => $blocked['fingerprint'], 'reason' => 'Test'])->assertConflict();
        $this->assertDatabaseCount('service_provider_migrations', 0);

        DB::table('provider_credential_defaults')->where('provider_id', $destination)->update(['mode' => 'otp']);
        $single = [...$scope, 'tenant_id' => $first];
        $plan = $this->postJson($preview, $single)->assertJsonPath('can_execute', true)->assertJsonCount(1, 'tenants')->json();
        $request = [...$single, 'operation_id' => Str::uuid7()->toString(), 'fingerprint' => $plan['fingerprint'], 'reason' => 'Planned migration'];
        $this->postJson($execute, $request)->assertOk()->assertJsonPath('tenants_migrated', 1);
        $this->postJson($execute, $request)->assertOk()->assertJsonPath('tenants_migrated', 1);
        $this->assertDatabaseHas('customer_tenants', ['id' => $first, 'organization_id' => $organization, 'provider_id' => $destination]);
        $this->assertDatabaseHas('customer_tenants', ['id' => $second, 'provider_id' => $source]);
        $this->assertDatabaseHas('customer_accounts', ['user_id' => $operator, 'tenant_id' => $first, 'state' => 'Active']);
        $this->assertDatabaseHas('customer_business_associations', ['user_id' => $operator, 'business_id' => $shop, 'active' => true]);
        $this->assertDatabaseHas('business_identities', ['id' => $shop, 'parent_id' => $organization]);
        $this->assertDatabaseHas('business_identities', ['id' => $source, 'kind' => 'Sub-Portal']);

        $whole = [...$scope, 'destination_id' => $main];
        $old = $this->postJson($preview, $whole)->assertJsonCount(1, 'tenants')->json();
        [$additional] = $this->tenant($source);
        $this->postJson($execute, [...$whole, 'operation_id' => Str::uuid7()->toString(), 'fingerprint' => $old['fingerprint'], 'reason' => 'Stale'])->assertConflict();
        $current = $this->postJson($preview, $whole)->assertJsonCount(2, 'tenants')->json();
        $this->postJson($execute, [...$whole, 'operation_id' => Str::uuid7()->toString(), 'fingerprint' => $current['fingerprint'], 'reason' => 'Consolidate'])
            ->assertOk()->assertJsonPath('tenants_migrated', 2);
        $this->assertDatabaseHas('customer_tenants', ['id' => $additional, 'provider_id' => $main]);
        $this->assertDatabaseHas('customer_tenants', ['id' => $unrelated, 'provider_id' => $main]);
        $this->assertDatabaseCount('service_provider_migrations', 2);
        $this->assertSame(2, DB::table('portal_events')->where('action', 'service_provider.migrated')->count());
    }

    private function tenant(string $provider): array
    {
        $organization = $this->business('Organization');
        $shop = $this->business('Shop', $organization);
        $owner = Str::uuid7()->toString();
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Owner']);
        foreach ([$organization, $shop] as $id) {
            DB::table('ownership_links')->insert(['id' => Str::uuid7()->toString(), 'business_id' => $id, 'owner_id' => $owner]);
        }
        $id = Str::uuid7()->toString();
        DB::table('customer_tenants')->insert(['id' => $id, 'organization_id' => $organization, 'provider_id' => $provider]);

        return [$id, $organization, $shop];
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $id, 'kind' => $kind, 'professional_name' => $kind, 'parent_id' => $parent]);

        return $id;
    }
}
