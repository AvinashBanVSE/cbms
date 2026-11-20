<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_businesses_confirm_without_sharing_authority(): void
    {
        [$shop, $shopUser, $shopParent] = $this->endpoint('Shop', 'AA1010', ['mapping.initiate', 'mapping.confirm', 'mapping.end']);
        [$distributor, $distributorUser, $distributorParent] = $this->endpoint('Distributor', 'AA1011', ['mapping.verify', 'mapping.end']);
        $this->assertNotSame($shopParent, $distributorParent);
        $endpoint = "/api/v1/shops/{$shop}/mappings";
        $this->postJson($endpoint, ['distributor_id' => $distributor])->assertUnauthorized();

        $this->actingAs($shopUser);
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertJsonPath('administration', [])
            ->assertJsonCount(3, 'mapping_contexts')->assertJsonMissing(['id' => $distributor]);
        DB::table('customer_permissions')->insert(['user_id' => $shopUser->id, 'business_id' => $shop, 'action' => 'mapping.verify']);
        $this->getJson('/api/v1/mapping-contexts')->assertOk()->assertJsonCount(3)->assertJsonMissing(['action' => 'mapping.verify']);
        $this->getJson('/api/v1/mapping-contexts')->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.id', $shop)->assertJsonMissing(['id' => $distributor]);
        DB::table('customer_business_scopes')->where('user_id', $shopUser->id)->delete();
        $this->postJson($endpoint, ['distributor_id' => $distributor])->assertForbidden();
        DB::table('customer_business_scopes')->insert(['user_id' => $shopUser->id, 'business_id' => $shop]);

        $this->postJson($endpoint, ['distributor_id' => $distributor, 'mapping_type' => 'unregistered'])
            ->assertUnprocessable()->assertJsonValidationErrors('mapping_type');

        $start = $this->postJson($endpoint, ['distributor_id' => $distributor])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private');
        $id = $start->json('id');
        $code = $start->json('code');
        $verify = "/api/v1/distributors/{$distributor}/mappings/verify";
        $confirm = "{$endpoint}/confirm";
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $id, 'state' => 'pending_distributor', 'code_digest' => hash('sha256', $code)]);
        $this->postJson($confirm, ['code' => $code])->assertUnprocessable();
        $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertForbidden();
        // A misconfigured assignment must never bridge separate tenants.
        foreach (['customer_business_associations', 'customer_business_scopes', 'customer_permissions'] as $table) {
            $extra = match ($table) {
                'customer_business_associations' => ['active' => true],
                'customer_permissions' => ['action' => 'mapping.verify'],
                default => [],
            };
            DB::table($table)->insert(['user_id' => $shopUser->id, 'business_id' => $distributor, ...$extra]);
        }
        $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertForbidden();
        DB::table('customer_business_scopes')->where('business_id', $distributor)->where('user_id', $shopUser->id)->delete();
        $this->getJson('/api/v1/mapping-contexts')->assertJsonMissing(['id' => $distributor]);

        $this->actingAs($distributorUser);
        $this->postJson($verify, ['shop_id' => Str::uuid7()->toString(), 'code' => $code])->assertUnprocessable();
        $second = $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertOk()->assertJsonPath('id', $id)->json('code');
        $this->assertNotSame($code, $second);
        $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $id, 'state' => 'pending_shop']);

        $this->actingAs($shopUser);
        $this->postJson($confirm, ['code' => $code])->assertUnprocessable();
        $this->postJson($confirm, ['code' => $second])->assertOk()->assertJsonPath('state', 'Active');
        $this->postJson($confirm, ['code' => $second])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$shop}/mappings/{$id}/end")->assertOk();
        $this->assertDatabaseHas('business_identities', ['id' => $shop, 'parent_id' => $shopParent]);
        $this->assertDatabaseHas('business_identities', ['id' => $distributor, 'parent_id' => $distributorParent]);
        $this->assertDatabaseCount('customer_business_scopes', 2);
        $this->assertDatabaseCount('distributor_shop_mapping_history', 4);
        $this->assertDatabaseHas('portal_events', ['subject_id' => $id, 'action' => 'mapping.activated']);
        $history = "/api/v1/businesses/{$shop}/mappings/{$id}/history";
        $this->getJson($history)->assertForbidden();
        DB::table('customer_permissions')->insert(['user_id' => $shopUser->id, 'business_id' => $shop, 'action' => 'mapping.history.view']);
        $this->getJson($history)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(4, 'data')->assertJsonPath('data.0.action', 'terminated')
            ->assertJsonPath('mapping_type.code', 'distributor_shop')
            ->assertJsonPath('mapping_type.allowed_transactions', [])
            ->assertJsonPath('mapping_type.shared_data_fields', [])
            ->assertJsonMissingPath('data.0.actor_id')->assertJsonMissingPath('data.0.code_digest');
        $this->getJson('/api/v1/audit-trail')->assertForbidden();
        $this->getJson("/api/v1/businesses/{$distributor}/mappings/{$id}/history")->assertForbidden();
        $this->getJson("/api/v1/businesses/{$shop}/mappings/".Str::uuid7().'/history')->assertNotFound();
        $this->assertDatabaseHas('portal_events', ['subject_id' => $id, 'action' => 'mapping.history_viewed', 'actor_id' => $shopUser->id]);
        $this->assertDatabaseCount('distributor_shop_mapping_history', 4);
    }

    public function test_expiry_and_revocation_block_verification(): void
    {
        [$shop, $shopUser] = $this->endpoint('Shop', 'AA1020', ['mapping.initiate', 'mapping.end']);
        [$distributor, $distributorUser] = $this->endpoint('Distributor', 'AA1021', ['mapping.verify']);
        $this->actingAs($shopUser);
        $path = "/api/v1/shops/{$shop}/mappings";
        $this->postJson($path, ['distributor_id' => Str::uuid7()->toString()])->assertUnprocessable();
        $pending = $this->postJson($path, ['distributor_id' => $distributor])->assertCreated()->json();
        $this->postJson("/api/v1/businesses/{$shop}/mappings/{$pending['id']}/end")->assertOk();
        $expired = $this->postJson($path, ['distributor_id' => $distributor])->assertCreated()->json();
        $this->travel(16)->minutes();
        $this->actingAs($distributorUser);
        foreach ([$pending, $expired] as $mapping) {
            $this->postJson("/api/v1/distributors/{$distributor}/mappings/{$mapping['id']}/verify", [
                'shop_id' => $shop, 'code' => $mapping['code'],
            ])->assertUnprocessable();
        }
    }

    public function test_mapping_is_bound_to_the_intended_distributor_and_supports_existing_clients(): void
    {
        [$shop, $shopUser] = $this->endpoint('Shop', 'AA1030', ['mapping.initiate', 'mapping.confirm']);
        [$distributor, $distributorUser] = $this->endpoint('Distributor', 'AA1031', ['mapping.verify']);
        [$unrelated, $unrelatedUser] = $this->endpoint('Distributor', 'AA1032', ['mapping.verify']);
        $path = "/api/v1/shops/{$shop}/mappings";
        $this->actingAs($shopUser);
        $pending = $this->postJson($path, ['distributor_id' => $distributor])->assertCreated()->json();
        $proof = ['shop_id' => $shop, 'code' => $pending['code']];

        $this->actingAs($unrelatedUser);
        $this->postJson("/api/v1/distributors/{$unrelated}/mappings/verify", $proof)->assertUnprocessable();
        $this->actingAs($distributorUser);
        $second = $this->postJson("/api/v1/distributors/{$distributor}/mappings/{$pending['id']}/verify", $proof)
            ->assertOk()->assertJsonPath('id', $pending['id'])->json('code');
        $this->actingAs($shopUser);
        $this->postJson("{$path}/{$pending['id']}/confirm", ['code' => $second, 'distributor_id' => $distributor])
            ->assertOk()->assertJsonPath('state', 'Active');
    }

    private function endpoint(string $kind, string $code, array $actions): array
    {
        $main = DB::table('business_identities')->where('kind', 'Main Portal')->value('id');
        if ($main === null) {
            $main = $this->business('Main Portal');
        }
        $parent = $this->business('Organization');
        $business = $this->business($kind, $parent);
        $tenant = Str::uuid7()->toString();
        $user = Str::uuid7()->toString();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $parent, 'provider_id' => $main]);
        DB::table('users')->insert(['id' => $user, 'code' => $code, 'email' => $code.'@example.test', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $user, 'tenant_id' => $tenant, 'state' => 'Active']);
        $binding = ['user_id' => $user, 'business_id' => $business];
        DB::table('customer_business_associations')->insert([...$binding, 'active' => true]);
        DB::table('customer_business_scopes')->insert($binding);
        foreach ($actions as $action) {
            DB::table('customer_permissions')->insert([...$binding, 'action' => $action]);
        }

        return [$business, User::findOrFail($user), $parent];
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'professional_name' => $kind]);

        return $id;
    }
}
