<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_cascade_restores_only_the_exact_changed_businesses_and_eligible_mappings(): void
    {
        $organization = $this->business('Organization');
        $other = $this->business('Organization');
        $shop = $this->business('Shop', $organization);
        $priorSuspended = $this->business('Shop', $organization, 'Suspended');
        $distributor = $this->business('Distributor', $other);
        $mapping = Str::uuid7()->toString();
        DB::table('distributor_shop_mappings')->insert(['id' => $mapping, 'shop_id' => $shop, 'distributor_id' => $distributor, 'state' => 'Active']);
        $path = "/api/v1/businesses/{$organization}/lifecycle";
        $this->getJson($path)->assertUnauthorized();
        $this->authenticate();
        $this->getJson($path)->assertOk()->assertJsonPath('state', 'Active');
        $this->postJson($path, $this->command('Closed', 0))->assertConflict();
        $suspend = $this->command('Suspended', 0);
        $result = $this->postJson($path, $suspend)->assertOk()->assertJsonPath('changed_businesses', 2)->assertJsonPath('changed_mappings', 1)->json();
        $this->postJson($path, $suspend)->assertExactJson($result);
        $this->postJson($path, [...$suspend, 'state' => 'Active'])->assertConflict();
        $this->assertDatabaseCount('business_lifecycle_operations', 1);
        $this->assertDatabaseHas('business_identities', ['id' => $distributor, 'operational_state' => 'Active']);
        $snapshot = json_decode(DB::table('business_lifecycle_operations')->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Suspended', $snapshot['entities'][$priorSuspended]['before']);
        $this->assertNull($snapshot['entities'][$priorSuspended]['after']);
        $this->assertSame('Active', $snapshot['mappings'][$mapping]['before']);

        $this->postJson($path, $this->command('Active', 1))->assertOk()->assertJsonPath('restores_operation_id', $suspend['operation_id']);
        $this->assertDatabaseHas('business_identities', ['id' => $shop, 'operational_state' => 'Active']);
        $this->assertDatabaseHas('business_identities', ['id' => $priorSuspended, 'operational_state' => 'Suspended']);
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $mapping, 'state' => 'Active']);

        $this->postJson($path, $this->command('Deactivated', 2))->assertOk()->assertJsonPath('changed_businesses', 3);
        $this->postJson("/api/v1/businesses/{$distributor}/lifecycle", $this->command('Suspended', 0))->assertOk();
        $this->postJson($path, $this->command('Active', 3))->assertOk()->assertJsonPath('changed_mappings', 0);
        $this->assertDatabaseHas('distributor_shop_mappings', ['id' => $mapping, 'state' => 'Deactivated']);
        $this->assertDatabaseHas('business_identities', ['id' => $priorSuspended, 'operational_state' => 'Suspended']);
        $this->assertDatabaseHas('portal_events', ['action' => 'business.lifecycle_changed', 'subject_id' => $organization]);
        $this->assertDatabaseHas('business_lifecycle_history', ['business_id' => $shop, 'operation_id' => $suspend['operation_id'], 'to_state' => 'Suspended']);
    }

    public function test_restoration_preserves_a_later_independent_child_transition(): void
    {
        $organization = $this->business('Organization');
        $shop = $this->business('Shop', $organization);
        $distributor = $this->business('Distributor', $organization);
        $this->authenticate();

        $organizationPath = "/api/v1/businesses/{$organization}/lifecycle";
        $suspend = $this->command('Suspended', 0);
        $this->postJson($organizationPath, $suspend)->assertOk()->assertJsonPath('changed_businesses', 3);
        $snapshot = DB::table('business_lifecycle_operations')->where('id', $suspend['operation_id'])->value('snapshot');

        $independent = $this->command('Deactivated', 1);
        $this->postJson("/api/v1/businesses/{$shop}/lifecycle", $independent)->assertOk()->assertJsonPath('state', 'Deactivated');
        $restore = $this->command('Active', 1);
        $this->postJson($organizationPath, $restore)->assertOk()
            ->assertJsonPath('restores_operation_id', $suspend['operation_id'])->assertJsonPath('changed_businesses', 2);
        $this->assertSame($snapshot, DB::table('business_lifecycle_operations')->where('id', $suspend['operation_id'])->value('snapshot'));
        $this->assertDatabaseHas('business_identities', [
            'id' => $shop, 'parent_id' => $organization, 'operational_state' => 'Deactivated',
            'lifecycle_version' => 2, 'lifecycle_operation_id' => $independent['operation_id'],
        ]);
        $this->assertDatabaseHas('business_identities', [
            'id' => $distributor, 'parent_id' => $organization, 'operational_state' => 'Active',
            'lifecycle_version' => 2, 'lifecycle_operation_id' => $restore['operation_id'],
        ]);
        $this->assertDatabaseMissing('business_lifecycle_history', ['business_id' => $shop, 'operation_id' => $restore['operation_id']]);
    }

    public function test_children_cannot_activate_under_an_inactive_organization_and_closure_is_terminal(): void
    {
        $organization = $this->business('Organization', state: 'Draft');
        $shop = $this->business('Shop', $organization, 'Draft');
        $this->authenticate();
        $child = "/api/v1/businesses/{$shop}/lifecycle";
        $parent = "/api/v1/businesses/{$organization}/lifecycle";
        $this->postJson($child, $this->command('Active', 0))->assertConflict();
        $this->postJson($parent, $this->command('Active', 0))->assertOk();
        $this->postJson($child, $this->command('Pending Verification', 0))->assertOk();
        $this->postJson($child, $this->command('Active', 1))->assertUnprocessable();
        $this->postJson($child, [...$this->command('Active', 1), 'verification_confirmed' => true])->assertOk();
        $this->postJson($child, $this->command('Closed', 2))->assertOk();
        $this->postJson($parent, $this->command('Closed', 1))->assertOk();
        $this->postJson($child, $this->command('Active', 3))->assertConflict();
        $this->postJson($parent, $this->command('Active', 2))->assertConflict();
        $this->getJson($parent)->assertJsonPath('allowed_transitions', []);
    }

    public function test_configured_closure_requires_an_independent_current_authorized_reviewer(): void
    {
        $business = $this->business('Organization');
        $other = $this->business('Organization');
        $this->authenticate();
        $requester = User::where('code', 'AA1932')->firstOrFail();
        $portal = DB::table('portal_installation')->value('portal_id');
        $reviewer = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $reviewer, 'code' => 'AA1933', 'email' => 'reviewer@example.test', 'password' => 'unused']);
        $permission = ['business_id' => $portal, 'user_id' => $reviewer, 'permission' => 'business_structure.lifecycle'];
        DB::table('portal_grants')->insert($permission);
        $path = "/api/v1/businesses/{$business}/lifecycle";
        $policy = ['reviewers' => ['AA1933'], 'expiry_minutes' => 1];
        $this->postJson($path.'/closure-policy', $policy)->assertForbidden();
        DB::table('portal_grants')->insert([
            'business_id' => $portal, 'user_id' => $requester->id, 'permission' => 'business_structure.closure_policy',
        ]);
        $this->postJson($path.'/closure-policy', [...$policy, 'reviewers' => ['AA9999']])->assertUnprocessable();
        $this->postJson($path.'/closure-policy', $policy)->assertExactJson(['reviewer_count' => 1]);
        $this->freezeSecond();
        $first = $this->command('Closed', 0);
        $this->postJson($path, $first)->assertAccepted()->assertJsonPath('approval_id', $first['operation_id']);
        $this->postJson($path, $first)->assertAccepted()->assertJsonPath('approval_id', $first['operation_id']);
        $this->getJson($path.'/closure-approvals')->assertOk()->assertJsonPath('data.0.id', $first['operation_id']);
        $decision = $path.'/closure-approvals/'.$first['operation_id'];
        $this->postJson($decision, ['decision' => 'approve'])->assertForbidden();
        $this->actingAs(User::findOrFail($reviewer));
        $this->postJson(str_replace($business, $other, $decision), ['decision' => 'approve'])->assertNotFound();
        $this->travel(1)->minute();
        $this->postJson($decision, ['decision' => 'approve'])->assertConflict()->assertJsonPath('status', 'expired');

        $this->actingAs($requester);
        $stale = $this->command('Closed', 0);
        $this->postJson($path, $stale)->assertAccepted();
        $this->postJson($path, $this->command('Suspended', 0))->assertOk();
        $this->actingAs(User::findOrFail($reviewer));
        $this->postJson($path.'/closure-approvals/'.$stale['operation_id'], ['decision' => 'approve'])
            ->assertConflict()->assertJsonPath('status', 'stale');

        $this->actingAs($requester);
        $fresh = $this->command('Closed', 1);
        $this->postJson($path, $fresh)->assertAccepted();
        $decision = $path.'/closure-approvals/'.$fresh['operation_id'];
        $this->actingAs(User::findOrFail($reviewer));
        DB::table('portal_grants')->where($permission)->delete();
        $this->postJson($decision, ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseHas('business_identities', ['id' => $business, 'operational_state' => 'Suspended']);
        DB::table('portal_grants')->insert($permission);
        $this->postJson($decision, ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('state', 'Closed');
        $this->postJson($decision, ['decision' => 'approve'])->assertConflict();
        $this->actingAs($requester);
        $this->postJson($path, $fresh)->assertOk()->assertJsonPath('state', 'Closed');
        $this->assertDatabaseHas('portal_events', [
            'action' => 'business.closure_approval_finished', 'subject_id' => $fresh['operation_id'], 'actor_id' => $reviewer,
        ]);
    }

    private function authenticate(): void
    {
        $portal = $this->business('Main Portal');
        $id = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('users')->insert(['id' => $id, 'code' => 'AA1932', 'email' => 'administrator@example.test', 'password' => 'unused']);
        DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $id, 'permission' => 'business_structure.lifecycle']);
        $this->actingAs(User::findOrFail($id));
    }

    private function command(string $state, int $version): array
    {
        return ['state' => $state, 'expected_version' => $version, 'operation_id' => Str::uuid7()->toString(), 'reason' => 'Authorized lifecycle change'];
    }

    private function business(string $kind, ?string $parent = null, string $state = 'Active'): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'professional_name' => $kind, 'operational_state' => $state,
        ]);

        return $id;
    }
}
