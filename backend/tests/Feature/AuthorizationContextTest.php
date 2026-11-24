<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Consolidated post-login context endpoint (Stream 1.2). */
final class AuthorizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_unauthorized(): void
    {
        $this->getJson('/api/v1/authorization-context')->assertStatus(401);
    }

    public function test_returns_identity_account_state_and_business_decisions(): void
    {
        $portal = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);

        $organization = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $organization, 'kind' => 'Organization', 'professional_name' => 'Org']);
        $shop = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $organization]);

        $tenant = Str::uuid7()->toString();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $portal]);

        $id = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $id, 'code' => 'AC0001', 'email' => 'ac@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);

        DB::table('customer_business_associations')->insert(['user_id' => $id, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $id, 'business_id' => $shop]);
        DB::table('customer_permissions')->insert(['user_id' => $id, 'business_id' => $shop, 'action' => 'mapping.initiate']);

        $this->actingAsUser($id);

        $response = $this->getJson('/api/v1/authorization-context')->assertOk();
        $response->assertJsonPath('account_state', 'Active')
            ->assertJsonPath('session_valid', true)
            ->assertJsonPath('identity.id', $id);

        $decision = collect($response->json('decisions'))->firstWhere('business_id', $shop);
        $this->assertNotNull($decision);
        $this->assertSame(['mapping.initiate'], $decision['allowed']);
        $this->assertGreaterThanOrEqual(1, $response->json('catalogue_version'));
    }

    private function actingAsUser(string $id): void
    {
        $this->actingAs(User::query()->findOrFail($id));
    }
}
