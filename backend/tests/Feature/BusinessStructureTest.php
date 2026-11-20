<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_explicitly_authorized_users_may_manage_structure(): void
    {
        $this->getJson('/api/v1/businesses')->assertUnauthorized();
        $this->postJson('/api/v1/businesses')->assertUnauthorized();
        $this->authenticate(false);
        $this->getJson('/api/v1/businesses')->assertForbidden();
        $this->postJson('/api/v1/businesses')->assertForbidden();
    }

    public function test_creates_an_independent_provider_and_customer_structure(): void
    {
        $main = $this->authenticate(true);
        $provider = $this->create(['kind' => 'Sub-Portal', 'new_owner_name' => 'Provider owner']);
        $organization = $this->create(['kind' => 'Organization', 'provider_id' => $provider, 'new_owner_name' => 'Customer owner']);
        $distributor = $this->create(['kind' => 'Distributor', 'parent_id' => $organization]);
        $shop = $this->create(['kind' => 'Shop', 'parent_id' => $organization]);

        $owner = DB::table('ownership_links')->where('business_id', $organization)->value('owner_id');
        $providerOwner = DB::table('ownership_links')->where('business_id', $provider)->value('owner_id');
        $this->assertNotSame($owner, $providerOwner);
        $secondOrganization = $this->create(['kind' => 'Organization', 'provider_id' => $main, 'owner_ids' => [$owner]]);
        $this->assertDatabaseHas('customer_tenants', ['organization_id' => $secondOrganization, 'provider_id' => $main]);
        $this->assertDatabaseHas('customer_tenants', ['organization_id' => $organization, 'provider_id' => $provider]);
        $this->assertDatabaseHas('business_identities', ['id' => $organization, 'parent_id' => null]);
        $ids = DB::table('ownership_links')->where('owner_id', $owner)->pluck('id', 'business_id');
        $this->assertCount(4, $ids);
        $this->assertNotSame($ids[$shop], $ids[$distributor]);
        $this->assertDatabaseHas('portal_events', ['subject_id' => $shop, 'action' => 'business.created']);
        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonFragment(['owner_id' => $owner]);
    }

    public function test_rejects_invalid_parent_provider_and_ownership(): void
    {
        $main = $this->authenticate(true);
        $this->invalid(['kind' => 'Sub-Portal'], 'owner_ids');
        $this->invalid(['kind' => 'Shop', 'parent_id' => $main], 'parent_id');
        $this->invalid(['kind' => 'Organization', 'provider_id' => Str::uuid7()->toString(), 'new_owner_name' => 'Temporary'], 'provider_id');
        $this->assertDatabaseCount('ownership_holders', 0);
        $organization = $this->create(['kind' => 'Organization', 'provider_id' => $main, 'new_owner_name' => 'Owner']);
        $this->invalid(['kind' => 'Shop', 'parent_id' => $organization, 'new_owner_name' => 'Another owner'], 'new_owner_name');
        $this->invalid(['kind' => 'Organization', 'provider_id' => $organization, 'new_owner_name' => 'Another owner'], 'provider_id');
        $this->assertDatabaseCount('customer_tenants', 1);
        $this->assertDatabaseCount('ownership_holders', 1);
    }

    private function authenticate(bool $allowed): string
    {
        $portal = Str::uuid7()->toString();
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'CBM']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('users')->insert(['id' => $id, 'code' => 'AA0001', 'email' => 'test@example.test', 'password' => 'unused']);
        if ($allowed) {
            foreach (['business_structure.view', 'business_structure.create'] as $permission) {
                DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $id, 'permission' => $permission]);
            }
        }
        $this->actingAs(User::findOrFail($id));

        return $portal;
    }

    private function create(array $data): string
    {
        return $this->postJson('/api/v1/businesses', $data + ['professional_name' => $data['kind'], 'legal_name' => 'Legal name'])
            ->assertCreated()->json('id');
    }

    private function invalid(array $data, string $field): void
    {
        $this->postJson('/api/v1/businesses', $data + ['professional_name' => $data['kind'], 'legal_name' => 'Legal name'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
