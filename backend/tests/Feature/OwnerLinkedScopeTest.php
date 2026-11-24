<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\OwnerLinkedScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Exercised §7.5 owner-linked cross-entity scope: Distributor->Shop only via shared Owner + governed rule. */
final class OwnerLinkedScopeTest extends TestCase
{
    use RefreshDatabase;

    private OwnerLinkedScope $scope;

    private string $portal;

    private string $organization;

    private string $tenant;

    private string $distributor;

    private string $shopA;

    private string $shopB;

    private string $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new OwnerLinkedScope;

        $this->portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $this->portal]);

        $this->organization = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->organization, 'kind' => 'Organization', 'professional_name' => 'Org']);
        $this->tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $this->tenant, 'organization_id' => $this->organization, 'provider_id' => $this->portal]);

        $this->distributor = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->distributor, 'kind' => 'Distributor', 'professional_name' => 'Dist', 'parent_id' => $this->organization]);
        $this->shopA = (string) Str::uuid7();
        $this->shopB = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->shopA, 'kind' => 'Shop', 'professional_name' => 'A', 'parent_id' => $this->organization]);
        DB::table('business_identities')->insert(['id' => $this->shopB, 'kind' => 'Shop', 'professional_name' => 'B', 'parent_id' => $this->organization]);

        // An active Distributor<->ShopA mapping. ShopB is mapped to nothing shared.
        DB::table('distributor_shop_mappings')->insert(['id' => (string) Str::uuid7(), 'shop_id' => $this->shopA, 'distributor_id' => $this->distributor, 'state' => 'Active']);

        // An actor holding a governed role on the Distributor's own business context.
        $this->actor = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $this->actor, 'code' => 'OL0001', 'email' => 'ol@test.invalid', 'password' => 'unused']);
        $role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $this->distributor, 'name' => 'Dist Worker', 'governed' => true, 'version' => 1]);
        DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $this->actor]);
    }

    /** Enable the Main Portal-governed owner-linked purpose for mapping.initiate on Shop. */
    private function governOwnerLinked(): void
    {
        DB::table('owner_linked_scope_rules')->insert(['id' => (string) Str::uuid7(), 'action' => 'mapping.initiate', 'target_kind' => 'Shop', 'enabled' => true]);
    }

    public function test_owner_linked_shop_allowed_only_with_shared_owner_and_governed_rule(): void
    {
        $owner = (string) Str::uuid7();
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Holder']);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->distributor]);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->shopA]);

        // Without the governed rule: mapping + shared owner is NOT enough.
        $this->assertFalse($this->scope->allows($this->actor, $this->shopA, 'mapping.initiate'));

        $this->governOwnerLinked();
        $this->assertTrue($this->scope->allows($this->actor, $this->shopA, 'mapping.initiate'));
    }

    public function test_mapped_shop_without_shared_owner_is_denied(): void
    {
        $owner = (string) Str::uuid7();
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Holder']);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->distributor]);
        // shopA is mapped but has NO owner link in common with the Distributor.
        $this->governOwnerLinked();

        $this->assertFalse($this->scope->allows($this->actor, $this->shopA, 'mapping.initiate'));
    }

    public function test_shared_owner_shop_without_mapping_is_denied(): void
    {
        $owner = (string) Str::uuid7();
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Holder']);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->distributor]);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->shopB]);
        $this->governOwnerLinked();

        // shopB shares an owner but is not mapped to the Distributor -> outside scope.
        $this->assertFalse($this->scope->allows($this->actor, $this->shopB, 'mapping.initiate'));
    }

    public function test_non_shop_target_is_denied(): void
    {
        $owner = (string) Str::uuid7();
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Holder']);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->distributor]);
        DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $this->organization]);
        $this->governOwnerLinked();

        $this->assertFalse($this->scope->allows($this->actor, $this->organization, 'mapping.initiate'));
    }
}
