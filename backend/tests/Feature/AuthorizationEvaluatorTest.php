<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\AuthorizationEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Exercised §6 semantics for the shadow central evaluator (grant union, deny-wins, unknown-deny). */
final class AuthorizationEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluator_decision_semantics(): void
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);

        $organization = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $organization, 'kind' => 'Organization', 'professional_name' => 'Org']);
        $tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $portal]);

        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => 'EV0001', 'email' => 'ev@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);

        $evaluator = new AuthorizationEvaluator;

        // mapping.* actions are only applicable to a Shop/Distributor, so probe the positive
        // grant + deny-wins path against a Shop.
        $shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $organization]);

        DB::table('customer_business_associations')->insert(['user_id' => $id, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $id, 'business_id' => $shop]);
        DB::table('customer_permissions')->insert(['user_id' => $id, 'business_id' => $shop, 'action' => 'mapping.initiate']);

        $granted = $evaluator->decide($id, $shop);
        $this->assertSame(['mapping.initiate'], $granted->allowed);
        $this->assertSame([], $granted->denied);
        $this->assertSame(1, $granted->catalogueVersion);

        // Explicit deny wins over the granted action.
        DB::table('entity_permission_denies')->insert([
            'id' => (string) Str::uuid7(), 'business_id' => $shop, 'user_id' => $id,
            'action' => 'mapping.initiate', 'reason' => 'probe deny', 'created_by' => $id,
        ]);
        $denied = $evaluator->decide($id, $shop);
        $this->assertSame([], $denied->allowed);
        $this->assertSame(['mapping.initiate'], $denied->denied);
        $this->assertSame('probe deny', $denied->denyReasons['mapping.initiate']);

        // Unknown business denies (§6).
        $unknown = $evaluator->decide($id, (string) Str::uuid7());
        $this->assertSame([], $unknown->allowed);
        $this->assertSame('unknown_business', $unknown->source);
    }
}
