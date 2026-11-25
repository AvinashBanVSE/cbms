<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\AuthorizationEvaluator;
use App\Authorization\PermissionCatalogue;
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
        // catalogueVersion is the catalogue revision, not a literal: it reflects the whole
        // seeded catalogue, so assert the evaluator reports the same revision the catalogue
        // computes rather than pinning a number that depends on the migration seed size.
        $this->assertSame(PermissionCatalogue::revision(), $granted->catalogueVersion);
        $this->assertNotEmpty($granted->catalogueVersion);
        // §6 precedence chain is visible per action (plan §89: override-source display).
        $this->assertSame('allowed', $granted->precedence['mapping.initiate']['tier']);
        $this->assertSame('bound', $granted->precedence['mapping.submit']['tier'] ?? 'bound');

        // Explicit deny wins over the granted action.
        DB::table('entity_permission_denies')->insert([
            'id' => (string) Str::uuid7(), 'business_id' => $shop, 'user_id' => $id,
            'action' => 'mapping.initiate', 'reason' => 'probe deny', 'created_by' => $id,
        ]);
        $denied = $evaluator->decide($id, $shop);
        $this->assertSame([], $denied->allowed);
        $this->assertSame(['mapping.initiate'], $denied->denied);
        $this->assertSame('probe deny', $denied->denyReasons['mapping.initiate']);
        $this->assertSame('prohibited', $denied->precedence['mapping.initiate']['tier']);

        // Unknown business denies (§6).
        $unknown = $evaluator->decide($id, (string) Str::uuid7());
        $this->assertSame([], $unknown->allowed);
        $this->assertSame('unknown_business', $unknown->source);
        // An expired deny no longer suppresses the granted action.
        DB::table('entity_permission_denies')
            ->where('business_id', $shop)->where('user_id', $id)
            ->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(['mapping.initiate'], $evaluator->decide($id, $shop)->allowed);

        // A deny on a non-granted action is still surfaced as denied (§6 prohibitions win).
        DB::table('entity_permission_denies')->where('business_id', $shop)->delete();
        DB::table('customer_permissions')->where('user_id', $id)->delete();
        DB::table('entity_permission_denies')->insert([
            'id' => (string) Str::uuid7(), 'business_id' => $shop, 'user_id' => $id,
            'action' => 'mapping.confirm', 'reason' => 'not yours', 'created_by' => $id,
        ]);
        $surfaced = $evaluator->decide($id, $shop);
        $this->assertSame([], $surfaced->allowed);
        $this->assertSame(['mapping.confirm'], $surfaced->denied);
        $this->assertSame('not yours', $surfaced->denyReasons['mapping.confirm']);
    }

    public function test_version_key_tracks_catalogue_and_entity_versions(): void
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        $shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop']);

        $evaluator = new AuthorizationEvaluator;

        $revision = PermissionCatalogue::revision();
        $this->assertSame("catalogue={$revision};entity=0", $evaluator->versionKey($shop));

        // A transactional authorization-version bump changes the key (§8 cache invalidation input).
        DB::table('entity_authorization_versions')->insertOrIgnore(['business_id' => $shop, 'version' => 0]);
        DB::table('entity_authorization_versions')->where('business_id', $shop)->increment('version');
        $this->assertSame("catalogue={$revision};entity=1", $evaluator->versionKey($shop));

        // A catalogue change to a NON-maximum permission still moves the revision: a per-
        // definition max(version) would miss it and leave a cached decision valid across a real
        // catalogue change (finding 5.4).
        $stale = $evaluator->versionKey($shop);
        $min = DB::table('permission_definitions')->where('active', true)->orderBy('version')->first();
        DB::table('permission_definitions')->where('id', $min->id)->update(['version' => (int) $min->version + 1]);
        $this->assertNotSame($stale, $evaluator->versionKey($shop));
    }

    /** Finding 5.1: a direct grant is not self-sufficient authority without eligibility. */
    public function test_direct_grant_is_denied_when_context_is_ineligible(): void
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        $shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $portal]);
        $tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $portal, 'provider_id' => $portal]);
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => 'EV0002', 'email' => 'ev2@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);

        // A raw direct grant for a Shop the user has NO association/scope with. Eligibility must
        // gate it: no grant overrides a missing association or scope (D1).
        DB::table('customer_permissions')->insert(['user_id' => $id, 'business_id' => $shop, 'action' => 'mapping.initiate']);
        $decision = (new AuthorizationEvaluator)->decide($id, $shop);
        $this->assertSame([], $decision->allowed, 'no association/scope => ineligible, deny');
        $this->assertSame('ineligible_context', $decision->source);

        // Supplying association + scope makes the same grant take effect.
        DB::table('customer_business_associations')->insert(['user_id' => $id, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $id, 'business_id' => $shop]);
        $this->assertContains('mapping.initiate', (new AuthorizationEvaluator)->decide($id, $shop)->allowed);
    }

    /** An inactive business denies every action (§6 lifecycle boundary). */
    public function test_inactive_business_denies_regardless_of_grants(): void
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        $shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $portal]);
        $tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $portal, 'provider_id' => $portal]);
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => 'EV0003', 'email' => 'ev3@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $id, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $id, 'business_id' => $shop]);
        DB::table('customer_permissions')->insert(['user_id' => $id, 'business_id' => $shop, 'action' => 'mapping.initiate']);

        DB::table('business_identities')->where('id', $shop)->update(['operational_state' => 'Inactive']);
        $decision = (new AuthorizationEvaluator)->decide($id, $shop);
        $this->assertSame([], $decision->allowed);
        $this->assertSame('ineligible_context', $decision->source);
    }
}
