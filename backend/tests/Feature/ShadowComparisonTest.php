<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\ShadowComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShadowComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_reports_parity_and_logs_without_enforcing(): void
    {
        [$portal, $shop, $user] = $this->seedShop();

        // Both the evaluator and the legacy check consult a role grant, so grant via a governed
        // role assigned to the user (not a direct customer_permission, which only the evaluator sees).
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);

        $result = app(ShadowComparison::class)->compare($user, $shop, ['mapping.initiate']);

        $this->assertSame(1, $result['matches']);
        $this->assertSame(0, $result['divergences']);
        $this->assertTrue($result['results']['mapping.initiate']['match']);
        $this->assertTrue($result['results']['mapping.initiate']['evaluator']);
        $this->assertTrue($result['results']['mapping.initiate']['legacy']);
    }

    public function test_comparison_surfaces_divergence_as_evidence(): void
    {
        [$portal, $shop, $user] = $this->seedShop();

        // No grant at all: evaluator denies, legacy also denies — both sides deny, so it matches.
        $result = app(ShadowComparison::class)->compare($user, $shop, ['mapping.initiate']);

        $this->assertSame(1, $result['matches']);
        $this->assertFalse($result['results']['mapping.initiate']['evaluator']);
        $this->assertFalse($result['results']['mapping.initiate']['legacy']);
    }

    /**
     * @return array{0: string, 1: string, 2: string} portal, shop, user
     */
    private function seedShop(): array
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        $shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $portal]);
        $tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $portal, 'provider_id' => $portal]);
        $user = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $user, 'code' => 'SC0001', 'email' => 'sc@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $user, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $user, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $user, 'business_id' => $shop]);

        return [$portal, $shop, $user];
    }
}
