<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\AuthorizationEvaluator;
use App\Authorization\AuthorizationVersion;
use App\Authorization\ShadowComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_cached_decision_invalidate_on_authorization_change(): void
    {
        [$portal, $shop, $user] = $this->seedShop();
        config(['authorization.evaluator_decisions_cached' => true]);

        $evaluator = new AuthorizationEvaluator;
        $before = $evaluator->decide($user, $shop);
        $this->assertSame([], $before->allowed);

        // Grant via a governed role assignment; the version bump must invalidate the cached
        // decision so the next read reflects the new authority rather than the stale one.
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);
        AuthorizationVersion::bump($shop);

        $after = $evaluator->decide($user, $shop);
        $this->assertContains('mapping.initiate', $after->allowed);

        // The decision is genuinely cached: a second read with no change hits the cache, and
        // changing authority bumps the version (new key) so the stale entry is never served.
        $key = 'auth.decision.'.sha1($user.'|'.$evaluator->versionKey($shop));
        $this->assertNotNull(Cache::get($key));
        Cache::forget($key);
        $this->assertNull(Cache::get($key));
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
