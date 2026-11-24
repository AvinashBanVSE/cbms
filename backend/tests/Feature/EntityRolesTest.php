<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\EntityRoleAccess;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationOnboardingAccess;
use App\Authorization\ProviderAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EntityRolesTest extends TestCase
{
    use RefreshDatabase;

    private string $portal;

    private User $owner;

    private User $reviewer;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portal = $this->entity('Main Portal');
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $this->portal, 'stage' => 'ready']);
        $this->owner = $this->user();
        $this->reviewer = $this->user();
        foreach ([$this->owner, $this->reviewer] as $user) {
            DB::table('portal_grants')->insert(['user_id' => $user->id, 'business_id' => $this->portal, 'permission' => 'organization_users.manage']);
            DB::table('entity_user_scopes')->insert(['user_id' => $user->id, 'business_id' => $this->portal]);
        }
        $this->actingAs($this->owner);
    }

    public function test_entity_roles_work_in_all_five_contexts_without_implicit_scope(): void
    {
        foreach (['Main Portal', 'Sub-Portal', 'Organization', 'Shop', 'Distributor'] as $kind) {
            [$business, $user, $tenant] = $this->context($kind);
            $action = match ($kind) {
                'Main Portal' => 'audit_trail.view', 'Sub-Portal' => 'provider.organizations.view',
                'Organization' => 'organization_users.provision', default => 'mapping.initiate',
            };
            $role = $this->createRole($business, [$action]);
            $response = $this->assign($business, $role, $user, [$this->reviewer->code]);
            if (in_array($kind, ['Shop', 'Distributor'], true)) {
                $response->assertOk()->assertJsonPath('assigned', true);
            } else {
                $response->assertAccepted()->assertJsonPath('status', 'pending');
                $this->assertFalse(app(EntityRoleAccess::class)->allows($user->id, $business, $action));
                $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$response->json('id').'/decision', ['decision' => 'approve'])
                    ->assertOk()->assertJsonPath('status', 'approved');
            }
            $this->assertTrue(app(EntityRoleAccess::class)->allows($user->id, $business, $action));
            if ($kind === 'Main Portal') {
                $this->assertTrue(app(MainPortalAccess::class)->allows($user->id, $action));
            } elseif ($kind === 'Sub-Portal') {
                $this->assertNotNull(app(ProviderAccess::class)->directoryFor($user->id));
            } elseif ($kind === 'Organization') {
                $this->assertNotNull(app(OrganizationOnboardingAccess::class)->contextFor($user->id));
            } else {
                $this->actingAs($user);
                $this->assertNotEmpty(app(CustomerBusinessAccess::class)->mappingContexts());
                DB::table('customer_business_scopes')->where(['user_id' => $user->id, 'business_id' => $business])->delete();
                $this->assertEmpty(app(CustomerBusinessAccess::class)->mappingContexts());
            }
            $this->actingAs($this->owner);
            $this->postJson($this->url($business).'/'.$role.'/revoke', ['code' => $user->code, 'reason' => 'Security restriction'])->assertOk();
            $this->assertFalse(app(EntityRoleAccess::class)->allows($user->id, $business, $action));
        }
    }

    public function test_local_manager_can_grant_only_explicit_grant_limits_in_own_entity(): void
    {
        [$business, $manager, $tenant] = $this->context('Shop');
        $admin = $this->createRole($business, ['entity_roles.manage'], ['mapping.initiate']);
        $pending = $this->assign($business, $admin, $manager, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$pending.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->actingAs($manager)->getJson($this->url($business))->assertOk();
        $this->postJson($this->url($business), ['name' => 'Allowed', 'actions' => ['mapping.initiate'], 'grant_actions' => []])->assertCreated();
        $this->postJson($this->url($business), ['name' => 'Escalation', 'actions' => ['mapping.confirm'], 'grant_actions' => []])->assertForbidden();
        $this->postJson($this->url($business), ['name' => 'Delegate grant power', 'actions' => [], 'grant_actions' => ['mapping.initiate']])->assertForbidden();
        $other = $this->entity('Shop', DB::table('customer_tenants')->where('id', $tenant)->value('organization_id'));
        $this->getJson($this->url($other))->assertForbidden();
        $this->getJson($this->url($this->portal))->assertForbidden();
        DB::table('customer_business_associations')->where('user_id', $manager->id)->update(['active' => false]);
        $this->getJson($this->url($business))->assertForbidden();
    }

    public function test_use_permission_alone_never_confers_grant_power(): void
    {
        [$business, $manager] = $this->context('Shop');
        $admin = $this->createRole($business, ['entity_roles.manage', 'mapping.confirm']);
        $pending = $this->assign($business, $admin, $manager, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$pending.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->actingAs($manager)->postJson($this->url($business), ['name' => 'Copy power', 'actions' => ['mapping.confirm'], 'grant_actions' => []])->assertForbidden();
    }

    public function test_roles_are_independent_and_legacy_endpoint_cannot_bypass_approval(): void
    {
        [$s1, $user, $tenant] = $this->context('Shop');
        [$s2] = $this->context('Shop');
        $role = $this->createRole($s1, ['entity_roles.manage']);
        $this->createRole($s2, ['mapping.initiate']);
        $this->assign($s1, $role, $user)->assertUnprocessable();
        $this->assign($s1, $role, $user, [$this->owner->code])->assertUnprocessable();
        $this->postJson("/api/v1/tenants/{$tenant}/businesses/{$s1}/roles/{$role}/assign", ['code' => $user->code])->assertNotFound();
        $this->assign($s2, $role, $user)->assertNotFound();
        $this->postJson($this->url($s1), ['name' => 'Manager', 'actions' => [], 'grant_actions' => []])->assertConflict();
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_tenant_and_scope_boundaries_remain_mandatory(): void
    {
        [$shop, $user] = $this->context('Shop');
        [$other, $outsider] = $this->context('Shop');
        $role = $this->createRole($shop, ['mapping.initiate']);
        $this->assign($shop, $role, $outsider)->assertUnprocessable();
        DB::table('customer_business_associations')->insert(['user_id' => $outsider->id, 'business_id' => $shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $outsider->id, 'business_id' => $shop]);
        $this->assign($shop, $role, $outsider)->assertUnprocessable();
        DB::table('customer_business_scopes')->where('user_id', $user->id)->delete();
        $this->assign($shop, $role, $user)->assertUnprocessable();
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_versioned_edits_invalidate_pending_and_assigned_roles_cannot_change(): void
    {
        [$shop, $user] = $this->context('Shop');
        $role = $this->createRole($shop, ['entity_roles.manage']);
        $id = $this->assign($shop, $role, $user, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->assign($shop, $role, $user, [$this->reviewer->code])->assertJsonPath('id', $id);
        $body = ['name' => 'Changed', 'actions' => ['mapping.initiate'], 'grant_actions' => [], 'expected_version' => 2];
        $this->putJson($this->url($shop).'/'.$role, $body)->assertConflict();
        $body['expected_version'] = 1;
        $this->putJson($this->url($shop).'/'.$role, $body)->assertOk()->assertJsonPath('version', 2);
        $this->actingAs($this->reviewer)->postJson($this->url($shop).'/approvals/'.$id.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'invalidated');
        $this->actingAs($this->owner);
        $this->assign($shop, $role, $user)->assertConflict();
        $this->postJson($this->url($shop).'/'.$role.'/assign', ['code' => $user->code, 'expected_version' => 2])->assertOk();
        // Now that the role is assigned, an edit no longer changes it directly (409); it enters the
        // governed approval workflow instead (D3: evaluated against every affected assignment).
        $body['expected_version'] = 2;
        DB::table('role_approval_policies')->insert(['business_id' => $shop, 'operation' => 'edit', 'stages' => json_encode([['reviewers' => [$this->reviewer->id], 'required' => 1]])]);
        $editId = $this->putJson($this->url($shop).'/'.$role, $body)->assertAccepted()->json('id');
        $this->assertDatabaseHas('entity_role_changes', ['id' => $editId, 'status' => 'pending']);
        $this->actingAs($this->reviewer)->postJson($this->url($shop).'/approvals/'.$editId.'/decision', ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('organization_role_permissions', ['role_id' => $role, 'action' => 'mapping.initiate']);
        $this->assertSame(3, (int) DB::table('organization_business_roles')->where('id', $role)->value('version'));
        $this->assertDatabaseCount('organization_role_assignments', 1);
        // The approved edit cannot be replayed.
        $this->actingAs($this->reviewer)->postJson($this->url($shop).'/approvals/'.$editId.'/decision', ['decision' => 'approve'])
            ->assertConflict();
    }

    public function test_approval_requires_current_independent_authority_and_is_not_replayable(): void
    {
        [$shop, $user] = $this->context('Shop');
        $role = $this->createRole($shop, ['entity_roles.manage']);
        $id = $this->assign($shop, $role, $user, [$this->reviewer->code])->assertAccepted()->json('id');
        $url = $this->url($shop).'/approvals/'.$id.'/decision';
        $this->postJson($url, ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($user)->postJson($url, ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->owner)->getJson($this->url($shop).'/approvals')->assertOk()->assertJsonCount(1, 'data');
        DB::table('portal_grants')->where('user_id', $this->owner->id)->delete();
        $this->actingAs($this->reviewer)->postJson($url, ['decision' => 'approve'])->assertJsonPath('status', 'invalidated');
        $this->postJson($url, ['decision' => 'approve'])->assertConflict();
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_copy_is_a_versioned_snapshot_without_assignments_or_grant_authority(): void
    {
        [$source, $user] = $this->context('Shop');
        [$target] = $this->context('Shop');
        $role = $this->createRole($source, ['mapping.initiate'], ['mapping.confirm']);
        $data = ['source_business' => $source, 'source_role' => $role, 'name' => 'Independent', 'expected_version' => 1];
        $copy = $this->postJson($this->url($target).'/copy', $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('organization_business_roles', ['id' => $copy, 'business_id' => $target, 'template_id' => $role, 'template_version' => 1]);
        $this->assertDatabaseMissing('entity_role_grant_limits', ['role_id' => $copy]);
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->postJson($this->url($target).'/copy', $data)->assertConflict();
        $this->putJson($this->url($source).'/'.$role, ['name' => 'Changed', 'actions' => ['mapping.confirm'], 'grant_actions' => [], 'expected_version' => 1])->assertOk();
        $this->assertDatabaseHas('organization_role_permissions', ['role_id' => $copy, 'action' => 'mapping.initiate']);
        $data['name'] = 'Obsolete';
        $this->postJson($this->url($target).'/copy', $data)->assertConflict();
        $data['expected_version'] = 2;
        $this->postJson($this->url($this->portal).'/copy', $data)->assertUnprocessable();
    }

    public function test_approval_policy_stages_distinct_votes_expiry_and_rejection(): void
    {
        [$business, $user] = $this->context('Shop');
        $second = $this->user();
        DB::table('portal_grants')->insert(['user_id' => $second->id, 'business_id' => $this->portal, 'permission' => 'organization_users.manage']);
        $role = $this->createRole($business, ['mapping.initiate']);
        DB::table('role_approval_policies')->insert([
            'business_id' => $business, 'operation' => 'assign', 'expiry_minutes' => 5,
            'stages' => json_encode([
                ['reviewers' => [$this->reviewer->id, $second->id], 'required' => 2],
                ['reviewers' => [$second->id], 'required' => 1],
            ]),
        ]);
        $id = $this->assign($business, $role, $user)->assertAccepted()->json('id');
        $url = $this->url($business).'/approvals/'.$id.'/decision';
        $this->actingAs($this->reviewer)->postJson($url, ['decision' => 'approve'])->assertJsonPath('stage', 0);
        $this->postJson($url, ['decision' => 'approve'])->assertConflict();
        $this->actingAs($second)->postJson($url, ['decision' => 'approve'])->assertJsonPath('stage', 1);
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->postJson($url, ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->postJson($url, ['decision' => 'approve'])->assertConflict();
        $this->actingAs($this->owner);
        $this->assign($business, $role, $user)->assertOk()->assertJsonPath('assigned', true);
        $this->postJson($this->url($business).'/'.$role.'/revoke', ['code' => $user->code, 'reason' => 'Recheck'])->assertOk();
        $id = $this->assign($business, $role, $user)->assertAccepted()->json('id');
        $url = $this->url($business).'/approvals/'.$id.'/decision';
        $this->travel(6)->minutes();
        $this->actingAs($this->reviewer)->postJson($url, ['decision' => 'approve'])->assertJsonPath('status', 'expired');
        $this->actingAs($this->owner);
        $id = $this->assign($business, $role, $user)->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$id.'/decision', ['decision' => 'reject'])->assertJsonPath('status', 'rejected');
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_account_state_and_scope_changes_remove_role_access_immediately(): void
    {
        [$business, $user] = $this->context('Sub-Portal');
        $role = $this->createRole($business, ['provider.organizations.view']);
        $id = $this->assign($business, $role, $user, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$id.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->assertNotNull(app(ProviderAccess::class)->directoryFor($user->id));
        DB::table('provider_accounts')->where('user_id', $user->id)->update(['state' => 'Suspended']);
        $this->assertNull(app(ProviderAccess::class)->directoryFor($user->id));
        DB::table('provider_accounts')->where('user_id', $user->id)->update(['state' => 'Active']);
        DB::table('entity_user_scopes')->where('user_id', $user->id)->delete();
        $this->assertNull(app(ProviderAccess::class)->directoryFor($user->id));
        $this->assertFalse(app(EntityRoleAccess::class)->eligible($user->id, (string) Str::uuid7()));
        DB::table('business_identities')->where('id', $business)->update(['operational_state' => 'Suspended']);
        $this->assertFalse(app(EntityRoleAccess::class)->eligible($user->id, $business));
    }

    public function test_obsolete_pending_request_is_replaced_and_policy_changes_cannot_loosen_it(): void
    {
        [$business, $user] = $this->context('Shop');
        $role = $this->createRole($business, ['entity_roles.manage']);
        $first = $this->assign($business, $role, $user, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->putJson($this->url($business).'/'.$role, ['name' => 'Version 2', 'actions' => ['entity_roles.manage'], 'grant_actions' => [], 'expected_version' => 1])->assertOk();
        $second = $this->postJson($this->url($business).'/'.$role.'/assign', ['code' => $user->code, 'expected_version' => 2, 'reviewers' => [$this->reviewer->code]])->assertAccepted()->json('id');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseHas('entity_role_changes', ['id' => $first, 'status' => 'invalidated']);
        DB::table('permission_definitions')->where('code', 'entity_roles.manage')->update(['requires_approval' => false]);
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$second.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'invalidated');
        $this->assertDatabaseCount('organization_role_assignments', 0);
    }

    public function test_existing_roles_and_assignments_survive_the_schema_upgrade(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.migration_review' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('migration_review');
        try {
            $upgrade = database_path('migrations/2026_11_24_000053_generalize_entity_roles.php');
            $paths = glob(database_path('migrations/*.php'));
            sort($paths);
            foreach ($paths as $path) {
                if ($path !== $upgrade) {
                    (require $path)->up();
                }
            }
            $portal = $this->entity('Main Portal');
            $organization = $this->entity('Organization');
            $shop = $this->entity('Shop', $organization);
            $user = $this->user();
            $tenant = (string) Str::uuid7();
            DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $portal]);
            DB::table('customer_accounts')->insert(['user_id' => $user->id, 'tenant_id' => $tenant, 'state' => 'Active']);
            $role = (string) Str::uuid7();
            DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $shop, 'name' => 'Legacy']);
            DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => 'mapping.initiate']);
            DB::table('organization_role_assignments')->insert(['role_id' => $role, 'user_id' => $user->id]);
            $tables = ['organization_role_assignments', 'organization_role_permissions', 'portal_grants', 'customer_permissions', 'customer_business_scopes'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
            (require $upgrade)->up();
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson());
            }
            $this->assertFalse((bool) DB::table('organization_business_roles')->where('id', $role)->value('governed'));
            $this->assertSame(1, (int) DB::table('organization_business_roles')->where('id', $role)->value('version'));
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('migration_review');
        }
    }

    public function test_reviewer_without_role_management_sees_only_assigned_requests(): void
    {
        [$business, $localReviewer, $tenant] = $this->context('Shop');
        $reviewRole = $this->createRole($business, ['entity_roles.approve'], ['mapping.initiate']);
        $id = $this->assign($business, $reviewRole, $localReviewer, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($business).'/approvals/'.$id.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $recipient = $this->user();
        DB::table('customer_accounts')->insert(['user_id' => $recipient->id, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $recipient->id, 'business_id' => $business, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $recipient->id, 'business_id' => $business]);
        $this->actingAs($this->owner);
        $role = $this->postJson($this->url($business), ['name' => 'Worker', 'actions' => ['mapping.initiate'], 'grant_actions' => []])->assertCreated()->json('id');
        DB::table('role_approval_policies')->insert(['business_id' => $business, 'operation' => 'assign', 'stages' => json_encode([['reviewers' => [$localReviewer->id], 'required' => 1]])]);
        $id = $this->assign($business, $role, $recipient)->assertAccepted()->json('id');
        $this->actingAs($localReviewer)->getJson($this->url($business))->assertForbidden();
        $this->getJson($this->url($business).'/approvals')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->url($business).'/approvals/'.$id.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->getJson($this->url($business).'/approvals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_client_administration_accepts_scoped_roles_and_loses_access_on_scope_revocation(): void
    {
        [$organization, $user, $tenant] = $this->context('Organization');
        $role = $this->createRole($organization, ['clients.activate']);
        $id = $this->assign($organization, $role, $user, [$this->reviewer->code])->assertAccepted()->json('id');
        $this->actingAs($this->reviewer)->postJson($this->url($organization).'/approvals/'.$id.'/decision', ['decision' => 'approve'])->assertJsonPath('status', 'approved');
        $this->actingAs($user)->getJson('/api/v1/tenants/'.$tenant.'/clients')->assertOk();
        DB::table('customer_business_scopes')->where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/tenants/'.$tenant.'/clients')->assertForbidden();
    }

    public function test_business_scoped_direct_deny_overrides_roles_and_can_expire_or_be_removed(): void
    {
        [$business, $user] = $this->context('Shop');
        $role = $this->createRole($business, ['mapping.initiate']);
        $this->assign($business, $role, $user)->assertOk();
        $this->assertTrue(app(EntityRoleAccess::class)->allows($user->id, $business, 'mapping.initiate'));

        $this->actingAs($this->owner)->postJson($this->url($business).'/denies', [
            'user_id' => $user->id, 'action' => 'mapping.initiate', 'reason' => 'Temporary review',
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ])->assertCreated();
        $this->assertFalse(app(EntityRoleAccess::class)->allows($user->id, $business, 'mapping.initiate'));
        $this->getJson($this->url($business).'/denies')->assertOk()->assertJsonCount(1, 'data');

        $deny = DB::table('entity_permission_denies')->where(['business_id' => $business, 'user_id' => $user->id])->first();
        $this->deleteJson($this->url($business).'/denies/'.$deny->id)->assertOk();
        $this->assertTrue(app(EntityRoleAccess::class)->allows($user->id, $business, 'mapping.initiate'));
    }

    private function createRole(string $business, array $actions, array $limits = []): string
    {
        return $this->postJson($this->url($business), ['name' => 'Manager', 'actions' => $actions, 'grant_actions' => $limits])->assertCreated()->json('id');
    }

    private function assign(string $business, string $role, User $user, array $reviewers = [])
    {
        return $this->postJson($this->url($business).'/'.$role.'/assign', ['code' => $user->code, 'expected_version' => 1, 'reviewers' => $reviewers]);
    }

    private function url(string $business): string
    {
        return '/api/v1/entities/'.$business.'/roles';
    }

    private function entity(string $kind, ?string $parent = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $id, 'kind' => $kind, 'professional_name' => $kind, 'parent_id' => $parent]);

        return $id;
    }

    private function user(): User
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => sprintf('ER%04d', ++$this->sequence), 'email' => 'user'.$this->sequence.'@test.invalid', 'password' => 'unused']);

        return User::findOrFail($id);
    }

    private function context(string $kind): array
    {
        $user = $this->user();
        if ($kind === 'Main Portal' || $kind === 'Sub-Portal') {
            $business = $kind === 'Main Portal' ? $this->portal : $this->entity($kind, $this->portal);
            if ($kind === 'Sub-Portal') {
                DB::table('provider_accounts')->insert(['user_id' => $user->id, 'provider_id' => $business, 'state' => 'Active']);
            }
            DB::table('entity_user_scopes')->insert(['user_id' => $user->id, 'business_id' => $business]);

            return [$business, $user, null];
        }
        $organization = $this->entity('Organization');
        $tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $this->portal]);
        $business = $kind === 'Organization' ? $organization : $this->entity($kind, $organization);
        DB::table('customer_accounts')->insert(['user_id' => $user->id, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $user->id, 'business_id' => $business, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $user->id, 'business_id' => $business]);

        return [$business, $user, $tenant];
    }
}
