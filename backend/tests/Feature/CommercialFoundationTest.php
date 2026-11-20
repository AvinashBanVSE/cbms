<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercialFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_plans_and_explicit_coverage_preserve_business_boundaries(): void
    {
        $portal = $this->business('Main Portal');
        $provider = $this->business('Sub-Portal');
        $organization = $this->business('Organization');
        $otherOrganization = $this->business('Organization');
        $child = $this->business('Shop', $organization);
        $otherChild = $this->business('Shop', $otherOrganization);
        $tenant = Str::uuid7()->toString();
        $administrator = Str::uuid7()->toString();
        $owner = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $provider]);
        DB::table('users')->insert(['id' => $administrator, 'code' => 'AA3911', 'email' => 'commercial@example.test', 'password' => 'unused']);
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Owner']);
        DB::table('ownership_links')->insert(['id' => Str::uuid7()->toString(), 'business_id' => $organization, 'owner_id' => $owner]);
        $base = '/api/v1/commercial';
        $this->getJson($base.'?section=accounts')->assertUnauthorized();
        $this->actingAs(User::findOrFail($administrator));
        $this->getJson($base.'?section=accounts')->assertForbidden();
        foreach (['commercial.manage', 'business_structure.create'] as $permission) {
            DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $administrator, 'permission' => $permission]);
        }

        $contract = ['tenant_id' => $tenant, 'provider_id' => $provider, 'billing_party' => 'Contracting entity'];
        $this->postJson($base.'/accounts', [...$contract, 'provider_id' => $portal])->assertUnprocessable();
        $account = $this->postJson($base.'/accounts', $contract)->assertCreated()->json('id');
        $feature = ['code' => 'portal.reports', 'name' => 'Reports'];
        $this->postJson($base.'/entitlements', $feature)->assertCreated();
        $this->postJson($base.'/entitlements', $feature)->assertConflict();
        $product = ['name' => 'Organization services', 'provider_id' => $provider, 'family' => 'tenant'];
        $plan = $this->postJson($base.'/plans', $product)->assertCreated()->json('id');
        $this->postJson($base.'/plans', $product)->assertConflict();

        $publish = "{$base}/plans/{$plan}/versions";
        $terms = ['amount_paise' => 12345, 'term_days' => 30, 'entitlements' => ['portal.reports']];
        $this->postJson($publish, [...$terms, 'entitlements' => ['unknown.feature']])->assertUnprocessable();
        $version = $this->postJson($publish, $terms)->assertCreated()->assertJsonPath('version', 1)->json('id');
        $this->postJson($publish, [...$terms, 'amount_paise' => 20000])->assertCreated()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('commercial_plan_versions', ['id' => $version, 'amount_paise' => 12345]);
        $this->getJson($base.'?section=versions')->assertOk()->assertJsonFragment(['composition' => 'any']);

        $input = ['account_id' => $account, 'plan_version_id' => $version, 'starts_on' => '2032-02-28', 'renewal_mode' => 'manual'];
        $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'selected', 'business_ids' => [$otherChild]])->assertUnprocessable();
        $selected = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'selected', 'business_ids' => [$child]])->assertCreated()->json('id');
        $self = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'organization'])->assertCreated()->json('id');
        $network = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'network'])->assertCreated()->json('id');
        $future = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'future_network'])->assertCreated()->json('id');
        $futureChild = $this->postJson('/api/v1/businesses', ['kind' => 'Shop', 'parent_id' => $organization, 'professional_name' => 'Future Shop'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $future, 'business_id' => $futureChild]);
        $this->assertDatabaseMissing('commercial_subscription_coverage', ['subscription_id' => $network, 'business_id' => $futureChild]);
        $this->assertDatabaseMissing('commercial_subscription_coverage', ['subscription_id' => $selected, 'business_id' => $organization]);
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $self, 'business_id' => $organization]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $future, 'state' => 'Pending Activation', 'ends_before' => '2032-03-29']);
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonCount(4, 'data');
        $this->assertDatabaseCount('customer_permissions', 0);

        DB::table('customer_tenants')->where('id', $tenant)->update(['provider_id' => $portal]);
        $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'organization'])->assertConflict();
        $this->assertDatabaseHas('commercial_accounts', ['id' => $account, 'provider_id' => $provider]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $selected, 'plan_version_id' => $version]);
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'professional_name' => $kind,
        ]);

        return $id;
    }
}
