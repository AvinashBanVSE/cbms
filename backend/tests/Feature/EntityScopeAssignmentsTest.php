<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\EntityScopeAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Exercised D2 scope-selector resolution: self/selected/snapshot/dynamic + exclusions. */
final class EntityScopeAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private EntityScopeAssignments $scopes;

    private string $organization;

    private string $tenant;

    private string $user;

    private string $shopA;

    private string $shopB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scopes = new EntityScopeAssignments;

        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $portal]);

        $this->organization = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->organization, 'kind' => 'Organization', 'professional_name' => 'Org']);
        $this->tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $this->tenant, 'organization_id' => $this->organization, 'provider_id' => $portal]);

        $this->user = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $this->user, 'code' => 'SS0001', 'email' => 'ss@test.invalid', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $this->user, 'tenant_id' => $this->tenant, 'state' => 'Active']);

        $this->shopA = (string) Str::uuid7();
        $this->shopB = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->shopA, 'kind' => 'Shop', 'professional_name' => 'A', 'parent_id' => $this->organization]);
        DB::table('business_identities')->insert(['id' => $this->shopB, 'kind' => 'Shop', 'professional_name' => 'B', 'parent_id' => $this->organization]);

        foreach ([$this->shopA, $this->shopB] as $shop) {
            DB::table('customer_business_associations')->insert(['user_id' => $this->user, 'business_id' => $shop, 'active' => true]);
            DB::table('customer_business_scopes')->insert(['user_id' => $this->user, 'business_id' => $shop]);
        }
    }

    public function test_selected_selector_includes_selected_and_subtracts_exclusions(): void
    {
        $this->scopes->save($this->user, $this->shopA, $this->user, [
            'selector' => EntityScopeAssignments::SELECTED,
            'entities' => [$this->shopA, $this->shopB],
            'exclusions' => [$this->shopB],
            'expected_version' => 0,
        ], fn (): bool => true);

        $this->assertSame([$this->shopA], $this->scopes->resolve($this->user, $this->shopA));
    }

    public function test_snapshot_resolves_currently_eligible_entities(): void
    {
        $this->scopes->save($this->user, $this->shopA, $this->user, [
            'selector' => EntityScopeAssignments::SNAPSHOT,
            'expected_version' => 0,
        ], fn (): bool => true);

        $resolved = $this->scopes->resolve($this->user, $this->shopA);
        sort($resolved);
        $expected = [$this->shopA, $this->shopB];
        sort($expected);
        $this->assertSame($expected, $resolved);
    }

    public function test_widening_requires_expansion_authority(): void
    {
        $this->scopes->save($this->user, $this->shopA, $this->user, [
            'selector' => EntityScopeAssignments::SELECTED,
            'entities' => [$this->shopA],
            'expected_version' => 0,
        ], fn (): bool => true);

        // Adding an entity is an access expansion -> refused without authority.
        $this->expectException(HttpException::class);
        $this->scopes->save($this->user, $this->shopA, $this->user, [
            'selector' => EntityScopeAssignments::SELECTED,
            'entities' => [$this->shopA, $this->shopB],
            'expected_version' => 1,
        ], fn (): bool => false);
    }

    public function test_dynamic_selector_requires_express_grant(): void
    {
        $this->expectException(HttpException::class);
        $this->scopes->save($this->user, $this->shopA, $this->user, [
            'selector' => EntityScopeAssignments::DYNAMIC,
            'dynamic' => true,
            'anchor_id' => $this->organization,
            'predicates' => ['kinds' => ['Shop']],
            'expected_version' => 0,
        ], fn (): bool => false);
    }

    public function test_absent_selector_keeps_legacy_self_scope(): void
    {
        $this->assertSame([$this->shopA], $this->scopes->resolve($this->user, $this->shopA));
    }
}
