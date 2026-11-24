<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\MainPortalAccess;
use App\Authorization\MappingPermissions;
use App\Authorization\PermissionCatalogue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PermissionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_is_shared_but_applicability_is_independent(): void
    {
        $this->assertSame(PermissionCatalogue::actions('Shop'), PermissionCatalogue::actions('Distributor'));
        $id = DB::table('permission_definitions')->where('code', 'mapping.initiate')->value('id');
        DB::table('permission_applicability')->where('permission_id', $id)->where('entity_kind', 'Shop')->delete();
        $this->assertNotContains('mapping.initiate', PermissionCatalogue::actions('Shop'));
        $this->assertContains('mapping.initiate', PermissionCatalogue::actions('Distributor'));
        $this->expectException(ValidationException::class);
        MappingPermissions::validate('Shop', ['mapping.initiate']);
    }

    public function test_inactive_or_unassignable_permissions_cannot_be_assigned(): void
    {
        DB::table('permission_definitions')->where('code', 'mapping.initiate')->update(['assignable' => false]);
        $this->assertContains('mapping.initiate', PermissionCatalogue::actions('Shop'));
        $this->assertSame(6, MappingPermissions::maxActions());
        $this->expectException(ValidationException::class);
        MappingPermissions::validate('Distributor', ['mapping.initiate']);
    }

    public function test_retiring_permission_revokes_effective_portal_access_without_deleting_grants(): void
    {
        $user = $this->portalUser();
        $access = app(MainPortalAccess::class);
        $this->assertTrue($access->allows($user->id, 'organization_users.manage'));
        DB::table('permission_definitions')->where('code', 'organization_users.manage')->update(['active' => false]);
        $this->assertFalse($access->allows($user->id, 'organization_users.manage'));
        $this->assertDatabaseHas('portal_grants', ['user_id' => $user->id, 'permission' => 'organization_users.manage']);
        $this->actingAs($user)->getJson('/api/v1/authorization/permissions')->assertForbidden();
    }

    public function test_catalogue_endpoint_requires_authority(): void
    {
        $this->getJson('/api/v1/authorization/permissions')->assertUnauthorized();
        $user = $this->portalUser();
        $this->actingAs($user)->getJson('/api/v1/authorization/permissions')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(33, 'data');
        DB::table('portal_grants')->where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/authorization/permissions')->assertForbidden();
    }

    public function test_catalogue_migration_preserves_existing_grants(): void
    {
        $this->portalUser();
        $before = DB::table('portal_grants')->get()->toJson();
        $migration = require database_path('migrations/2026_11_24_000052_create_permission_catalogue.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('portal_grants')->get()->toJson());
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->assertDatabaseCount('customer_business_scopes', 0);
    }

    public function test_unknown_permission_and_wrong_entity_context_are_denied(): void
    {
        $user = $this->portalUser();
        $portal = DB::table('portal_installation')->value('portal_id');
        foreach (['unknown.operation', 'mapping.initiate'] as $permission) {
            DB::table('portal_grants')->insert(['user_id' => $user->id, 'business_id' => $portal, 'permission' => $permission]);
            $this->assertFalse(app(MainPortalAccess::class)->allows($user->id, $permission));
        }
    }

    public function test_governed_catalogue_edit_updates_presentation_but_not_operation_identity(): void
    {
        $user = $this->portalUser();
        $version = (int) DB::table('permission_definitions')->where('code', 'mapping.initiate')->value('version');

        $this->actingAs($user)->putJson('/api/v1/authorization/permissions/mapping.initiate', [
            'name' => 'Initiate a mapping request',
            'active' => true,
            'assignable' => false,
            'expected_version' => $version,
        ])->assertOk()->assertJsonPath('data.version', $version + 1)
            ->assertJsonPath('data.assignable', 0);

        // Operation identity is immutable through this endpoint.
        $definition = DB::table('permission_definitions')->where('code', 'mapping.initiate')->first();
        $this->assertSame('mapping.initiate', $definition->code);
        $this->assertSame('Initiate a mapping request', $definition->name);

        // Stale expected_version is rejected (lost-update guard).
        $this->actingAs($user)->putJson('/api/v1/authorization/permissions/mapping.initiate', [
            'name' => 'Again', 'active' => true, 'assignable' => true, 'expected_version' => $version,
        ])->assertConflict();
    }

    private function portalUser(): User
    {
        $portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'Portal']);
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $portal]);
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => 'ZZ0001', 'email' => 'catalogue@example.test', 'password' => 'unused']);
        DB::table('portal_grants')->insert(['user_id' => $id, 'business_id' => $portal, 'permission' => 'organization_users.manage']);

        return User::findOrFail($id);
    }
}
