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
        $this->getJson('/api/v1/mapping-contexts')->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.id', $shop)->assertJsonMissing(['id' => $distributor]);
        DB::table('customer_business_scopes')->where('user_id', $shopUser->id)->delete();
        $this->postJson($endpoint, ['distributor_id' => $distributor])->assertForbidden();
        DB::table('customer_business_scopes')->insert(['user_id' => $shopUser->id, 'business_id' => $shop]);

        $start = $this->postJson($endpoint, ['distributor_id' => $distributor])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private');
        $id = $start->json('id');
        $code = $start->json('code');
        $verify = "/api/v1/distributors/{$distributor}/mappings/{$id}/verify";
        $confirm = "{$endpoint}/{$id}/confirm";
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $id, 'state' => 'pending_distributor', 'code_digest' => hash('sha256', $code)]);
        $this->postJson($confirm, ['distributor_id' => $distributor, 'code' => $code])->assertUnprocessable();
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
        $second = $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertOk()->json('code');
        $this->assertNotSame($code, $second);
        $this->postJson($verify, ['shop_id' => $shop, 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $id, 'state' => 'pending_shop']);

        $this->actingAs($shopUser);
        $this->postJson($confirm, ['distributor_id' => $distributor, 'code' => $code])->assertUnprocessable();
        $this->postJson($confirm, ['distributor_id' => $distributor, 'code' => $second])->assertOk()->assertJsonPath('state', 'Active');
        $this->postJson($confirm, ['distributor_id' => $distributor, 'code' => $second])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$shop}/mappings/{$id}/end")->assertOk();
        $this->assertDatabaseHas('business_identities', ['id' => $shop, 'parent_id' => $shopParent]);
        $this->assertDatabaseHas('business_identities', ['id' => $distributor, 'parent_id' => $distributorParent]);
        $this->assertDatabaseCount('customer_business_scopes', 2);
        $this->assertDatabaseCount('distributor_shop_mapping_history', 4);
        $this->assertDatabaseHas('portal_events', ['subject_id' => $id, 'action' => 'mapping.activated']);
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
