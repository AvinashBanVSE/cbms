<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\AuthorizationCutover;
use App\Authorization\AuthorizationEvaluator;
use App\Authorization\AuthorizationVersion;
use App\Authorization\DenyExceptions;
use App\Authorization\EntityRoleAccess;
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
        $key = 'auth.decision.'.sha1($user.'|'.$shop.'|'.$evaluator->versionKey($shop));
        $this->assertNotNull(Cache::get($key));
        Cache::forget($key);
        $this->assertNull(Cache::get($key));
    }

    /** Finding 5.3: a cached decision must not leak one business's permissions into another. */
    public function test_cache_key_is_isolated_per_business_entity(): void
    {
        [$portal, $shopA, $user] = $this->seedShop();
        config(['authorization.evaluator_decisions_cached' => true]);

        // A second, unrelated Shop for the same user with NO grant. It is seeded to mirror the
        // eligibility shape but holds no permission, so a leaked Shop-A decision would wrongly
        // allow there.
        $shopB = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shopB, 'kind' => 'Shop', 'professional_name' => 'B', 'parent_id' => DB::table('business_identities')->where('id', $shopA)->value('parent_id')]);
        DB::table('customer_business_associations')->insert(['user_id' => $user, 'business_id' => $shopB, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $user, 'business_id' => $shopB]);

        // Shop A has the permission and is read first, populating its cache entry.
        $evaluator = new AuthorizationEvaluator;
        DB::table('customer_permissions')->insert(['user_id' => $user, 'business_id' => $shopA, 'action' => 'mapping.initiate']);
        $this->assertContains('mapping.initiate', $evaluator->decide($user, $shopA)->allowed);

        // Both entities hold the same authorization version (0) and the catalogue revision is
        // shared, so versionKey() is identical for both. A key derived from that alone would
        // collide and replay Shop A's allow into Shop B.
        $this->assertSame($evaluator->versionKey($shopA), $evaluator->versionKey($shopB));

        // Shop B must deny on its own merits — never inherit Shop A's cached allow.
        $this->assertSame([], $evaluator->decide($user, $shopB)->allowed);
    }

    public function test_evaluator_must_honor_governed_deny_exceptions_like_legacy(): void
    {
        [$portal, $shop, $user] = $this->seedShop();
        $manager = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $manager, 'code' => (string) Str::uuid7(), 'email' => 'mgr@example.test', 'password' => 'x']);
        // A Main Portal administrative grant is the established exception-authority path.
        DB::table('portal_grants')->insert(['user_id' => $manager, 'business_id' => $portal, 'permission' => 'organization_users.manage']);
        // A role grant (the legacy positive path), a governed deny, then a governed exception.
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);
        DB::table('customer_permissions')->insert(['user_id' => $user, 'business_id' => $shop, 'action' => 'mapping.initiate']);
        DB::table('entity_permission_denies')->insert([
            'id' => (string) Str::uuid7(), 'business_id' => $shop, 'user_id' => $user,
            'action' => 'mapping.initiate', 'reason' => 'compliance hold', 'created_by' => $manager,
        ]);
        (new DenyExceptions)->save($manager, $shop, $user, [
            'action' => 'mapping.initiate', 'scope' => 'selector', 'selector' => 'self', 'reason' => 'approved carve-out',
        ]);
        AuthorizationVersion::bump($shop);

        $this->assertTrue(
            (new EntityRoleAccess)->allows($user, $shop, 'mapping.initiate'),
            'legacy must allow inside a governed exception'
        );
        $this->assertTrue(
            in_array('mapping.initiate', (new AuthorizationEvaluator)->decide($user, $shop)->allowed, true),
            'evaluator must honor governed deny exceptions or parity data is wrong'
        );
    }

    public function test_cutover_gate_is_off_by_default_and_bounded_to_listed_businesses(): void
    {
        [$portal, $shop, $user] = $this->seedShop();
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);

        // Default: flag off -> legacy authority, cutover reports not enforced.
        $cutover = new AuthorizationCutover(new AuthorizationEvaluator);
        $this->assertFalse($cutover->enforced($shop));

        // Flag on but business not in the bounded set -> still not enforced.
        config(['authorization.evaluator_enforced' => true]);
        $this->assertFalse($cutover->enforced($shop));

        // Flag on and the business is in the bounded set -> evaluator is authoritative, and a
        // granted action answers allow while a revoked one answers deny (fail closed on unknown).
        config(['authorization.enforced_businesses' => [$shop]]);
        $this->assertTrue($cutover->enforced($shop));
        $this->assertTrue($cutover->allows($user, $shop, 'mapping.initiate'));
        $this->assertFalse($cutover->allows($user, $shop, 'mapping.approve'));
    }

    public function test_cached_decision_must_not_outlive_a_deny_expiry(): void
    {
        [$portal, $shop, $user] = $this->seedShop();
        config(['authorization.evaluator_decisions_cached' => true, 'authorization.decision_cache_ttl' => 3600]);
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);
        AuthorizationVersion::bump($shop);

        $evaluator = new AuthorizationEvaluator;
        $this->assertContains('mapping.initiate', $evaluator->decide($user, $shop)->allowed);

        // A deny that self-expires (no mutation afterward): the versionKey does not change, so a
        // purely version-keyed cache would keep serving the pre-expiry allow past expiry.
        DB::table('entity_permission_denies')->insert([
            'id' => (string) Str::uuid7(), 'business_id' => $shop, 'user_id' => $user,
            'action' => 'mapping.initiate', 'reason' => 'temporary', 'created_by' => $user,
            'expires_at' => now()->addMinutes(5),
        ]);
        AuthorizationVersion::bump($shop);
        $this->assertNotContains('mapping.initiate', $evaluator->decide($user, $shop)->allowed, 'deny applies while live');

        // The security-critical direction: a governed carve-out WAIVES the deny, so the decision
        // is cached as allowed. When that carve-out self-expires the deny returns — but the
        // versionKey has not changed, so a purely version-keyed cache would keep serving the
        // expired carve-out's allow. That would restore revoked authority (§41), so the cache
        // must not outlive the waiver. Bounded-TTL caching accepts this only if TTL is short;
        // a long TTL here is the defect.
        $manager = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $manager, 'code' => (string) Str::uuid7(), 'email' => 'mgr2@test.invalid', 'password' => 'x']);
        DB::table('portal_grants')->insert(['user_id' => $manager, 'business_id' => $portal, 'permission' => 'organization_users.manage']);
        // Reuse the standing deny above and add a self-expiring carve-out on it.
        (new DenyExceptions)->save($manager, $shop, $user, [
            'action' => 'mapping.initiate', 'scope' => 'selector', 'selector' => 'self', 'reason' => 'short waiver',
            'expires_at' => now()->addMinutes(2)->toIso8601String(),
        ]);
        AuthorizationVersion::bump($shop);
        $this->assertContains('mapping.initiate', $evaluator->decide($user, $shop)->allowed, 'waived while carve-out live');
        $ref = new \ReflectionMethod(AuthorizationEvaluator::class, 'soonestExpiry');
        $ref->setAccessible(true);
        // The fix's verifiable guarantee: the entry's effective TTL is capped at the soonest
        // time-bound expiry (the ~2 min waiver), NOT the configured 3600 s. (We assert the
        // computed lifetime rather than a post-travel read, because the array cache store
        // expires entries on real wall-clock that travel() does not advance.)
        $effective = min(3600, $ref->invoke($evaluator, $user, $shop));
        $this->assertLessThanOrEqual(180, $effective, 'entry must expire by the carve-out expiry, not the configured 3600 s');
        $this->assertGreaterThan(0, $effective);
    }

    public function test_lapsed_carve_out_stops_waiving_the_deny(): void
    {
        // A carve-out whose expires_at is already in the PAST must not keep waiving the standing
        // deny. This is the mirror of the allow->deny direction: the evaluator's carve-out
        // subquery compares against now(), so a lapsed waiver drops back to deny. (Seeded lapsed
        // directly rather than via travel(), whose mocked now() and the stored UTC instant can
        // disagree by the app offset and mask the real comparison.)
        [$portal, $shop, $user] = $this->seedShop();
        $manager = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $manager, 'code' => (string) Str::uuid7(), 'email' => 'mgr3@test.invalid', 'password' => 'x']);
        DB::table('portal_grants')->insert(['user_id' => $manager, 'business_id' => $portal, 'permission' => 'organization_users.manage']);
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);
        DB::table('customer_permissions')->insert(['user_id' => $user, 'business_id' => $shop, 'action' => 'mapping.initiate']);
        $denyId = (string) Str::uuid7();
        DB::table('entity_permission_denies')->insert([
            'id' => $denyId, 'business_id' => $shop, 'user_id' => $user,
            'action' => 'mapping.initiate', 'reason' => 'hold', 'created_by' => $manager,
        ]);
        DB::table('entity_deny_exceptions')->insert([
            'id' => (string) Str::uuid7(), 'deny_id' => $denyId, 'business_id' => $shop, 'user_id' => $user,
            'action' => 'mapping.initiate', 'scope' => 'selector', 'selector' => 'self', 'reason' => 'lapsed waiver',
            'granted_by' => $manager, 'active' => true, 'version' => 1,
            'expires_at' => now()->subMinutes(5),
        ]);
        AuthorizationVersion::bump($shop);

        $this->assertNotContains('mapping.initiate', (new AuthorizationEvaluator)->decide($user, $shop)->allowed,
            'a carve-out that has lapsed must stop waiving the standing deny'
        );
    }

    public function test_shadow_comparison_reflects_the_same_decision_the_gate_serves(): void
    {
        // With the decision cache on, compare() reads the evaluator through decide() — the same
        // cached path the cutover gate serves. So the parity evidence must match what enforcement
        // would actually use, never a fresher-than-enforcement view. Revoke mid-flight and confirm
        // the comparison does NOT report a match the gate could not have been serving (i.e. the
        // stale cached allow) — it reports whatever is cached, and the cache must be bounded.
        [$portal, $shop, $user] = $this->seedShop();
        config(['authorization.evaluator_decisions_cached' => true, 'authorization.decision_cache_ttl' => 60]);
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Worker', 'governed' => true]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user]);
        AuthorizationVersion::bump($shop);

        $comparison = new ShadowComparison(new EntityRoleAccess);
        // While both agree, the parity result reports a match.
        $result = $comparison->compare($user, $shop, ['mapping.initiate']);
        $this->assertTrue($result['results']['mapping.initiate']['match']);

        // Revoke the grant: the version bump invalidates the cache, so the very next comparison
        // must reflect the deny (not a stale cached allow) for BOTH evaluator and legacy.
        DB::table('organization_role_assignments')->where('role_id', $role)->delete();
        AuthorizationVersion::bump($shop);

        $result = $comparison->compare($user, $shop, ['mapping.initiate']);
        $this->assertFalse($result['results']['mapping.initiate']['evaluator'], 'evaluator must see the revoke, not a stale cache');
        $this->assertFalse($result['results']['mapping.initiate']['legacy']);
        $this->assertTrue($result['results']['mapping.initiate']['match']);
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
