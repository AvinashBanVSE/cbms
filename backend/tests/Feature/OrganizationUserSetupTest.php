<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ValidateOrganizationSession;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OrganizationUserSetupTest extends TestCase
{
    use RefreshDatabase;

    public static function modes(): array
    {
        return [['temporary_password'], ['otp'], ['both']];
    }

    #[DataProvider('modes')]
    public function test_authorized_users_can_establish_a_permanent_password(string $mode): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        $url = "/api/v1/tenants/{$tenant}";
        $this->postJson("{$url}/organization-users")->assertUnauthorized();
        $this->actingAs($administrator);
        $this->postJson("{$url}/credential-policy", ['mode' => $mode])->assertOk();

        $user = ['email' => 'authorized@example.test', 'business_id' => $shop, 'actions' => ['mapping.initiate']];
        $this->postJson("{$url}/organization-users", [...$user, 'actions' => ['mapping.verify']])->assertUnprocessable();
        $this->postJson("{$url}/organization-users", $user)->assertServiceUnavailable();

        $transport = new ArrayTransport;
        Mail::extend('memory-test', static fn () => $transport);
        config(['mail.default' => 'memory-test', 'mail.mailers.memory-test.transport' => 'memory-test']);
        $created = $this->postJson("{$url}/organization-users", $user)->assertCreated()->assertJsonPath('delivery', 'sent');
        $code = $created->json('user_code');
        $this->postJson("{$url}/organization-users", $user)->assertUnprocessable();
        $this->postJson("{$url}/organization-users/reissue", ['code' => $code])->assertTooManyRequests();
        $this->travel(1)->minutes();
        $this->postJson("{$url}/credential-policy", ['mode' => 'inherit'])->assertOk();
        $this->assertDatabaseHas('organization_user_setups', ['mode' => $mode]);
        $this->postJson("{$url}/organization-users/reissue", ['code' => $code])->assertOk()->assertJsonPath('delivery', 'sent');
        $body = $transport->messages()->last()->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('https://', $body);
        $this->assertStringContainsString($code, $body);
        $this->assertCount(2, $transport->messages());

        preg_match('/Temporary password: (\w+)/', $body, $temporary);
        preg_match('/One-time code: (\d{6})/', $body, $otp);
        $proof = ['code' => $code, 'temporary_password' => $temporary[1] ?? null, 'otp' => $otp[1] ?? null];
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/organization-setup/complete')->assertForbidden();
        $this->postJson('/api/v1/organization-setup/verify', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/organization-setup/verify', $proof)->assertOk();
        $this->postJson('/api/v1/organization-setup/verify', $proof)->assertUnprocessable();
        $this->assertDatabaseHas('organization_user_setups', [
            'mode' => $mode, 'temporary_hash' => null, 'temporary_expires_at' => null,
            'otp_hash' => null, 'otp_expires_at' => null,
        ]);
        $this->getJson('/api/v1/mapping-contexts')->assertUnauthorized();
        $this->postJson('/api/v1/organization-setup/complete', ['password' => 'short'])->assertUnprocessable();
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
        $password = 'new-test-only-password-that-is-long-enough';
        $this->postJson('/api/v1/organization-setup/complete', ['password' => $password, 'password_confirmation' => $password])->assertOk();
        $this->assertDatabaseCount('organization_user_setups', 0);
        $this->postJson('/api/v1/login', ['code' => $code, 'email' => $user['email'], 'password' => $password])->assertNoContent();
        $this->getJson('/api/v1/mapping-contexts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $shop);
        DB::table('portal_grants')->insert([
            'user_id' => DB::table('users')->where('code', $code)->value('id'),
            'business_id' => DB::table('portal_installation')->where('id', 1)->value('portal_id'),
            'permission' => 'organization_users.manage',
        ]);
        $this->postJson("{$url}/credential-policy", ['mode' => 'otp'])->assertForbidden();
    }

    public function test_provider_defaults_follow_the_authorized_hierarchy(): void
    {
        [$tenant, , $administrator] = $this->fixture();
        $policy = "/api/v1/tenants/{$tenant}/credential-policy";
        $this->getJson($policy)->assertUnauthorized();
        $this->actingAs($administrator);
        $this->getJson($policy)->assertOk()->assertExactJson(['mode' => null, 'source' => null]);

        $main = DB::table('portal_installation')->value('portal_id');
        $organization = DB::table('customer_tenants')->where('id', $tenant)->value('organization_id');
        $mainDefault = "/api/v1/providers/{$main}/credential-default";
        $this->postJson("/api/v1/providers/{$organization}/credential-default", ['mode' => 'otp'])->assertNotFound();
        $this->postJson($mainDefault, ['mode' => 'invalid'])->assertUnprocessable();
        $this->postJson($mainDefault, ['mode' => 'temporary_password'])->assertOk();
        $this->getJson($policy)->assertExactJson(['mode' => 'temporary_password', 'source' => 'main_portal']);

        $provider = Str::uuid7()->toString();
        $otherOrganization = Str::uuid7()->toString();
        $otherTenant = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            ['id' => $provider, 'kind' => 'Sub-Portal', 'professional_name' => 'Provider'],
            ['id' => $otherOrganization, 'kind' => 'Organization', 'professional_name' => 'Other Organization'],
        ]);
        DB::table('customer_tenants')->insert(['id' => $otherTenant, 'organization_id' => $otherOrganization, 'provider_id' => $main]);
        DB::table('customer_tenants')->where('id', $tenant)->update(['provider_id' => $provider]);
        $this->getJson($policy)->assertExactJson(['mode' => 'temporary_password', 'source' => 'main_portal']);
        $this->postJson("/api/v1/providers/{$provider}/credential-default", ['mode' => 'otp'])->assertOk();
        $this->getJson($policy)->assertExactJson(['mode' => 'otp', 'source' => 'sub_portal']);
        $this->postJson($policy, ['mode' => 'both'])->assertExactJson(['mode' => 'both', 'source' => 'organization']);
        $this->getJson("/api/v1/tenants/{$otherTenant}/credential-policy")
            ->assertExactJson(['mode' => 'temporary_password', 'source' => 'main_portal']);
        $this->postJson($policy, ['mode' => 'inherit'])->assertExactJson(['mode' => 'otp', 'source' => 'sub_portal']);
        $this->assertDatabaseHas('portal_events', ['subject_id' => $provider, 'action' => 'organization_user.provider_default_changed']);
        $this->getJson('/api/v1/tenants/'.Str::uuid7().'/credential-policy')->assertNotFound();
    }

    public function test_access_changes_keep_scope_association_and_actions_separate(): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        $id = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $id, 'code' => 'AA2000', 'email' => 'operator@example.test', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);
        $endpoint = "/api/v1/tenants/{$tenant}/organization-users/AA2000/business-access/{$shop}";
        $full = ['associated' => true, 'in_scope' => true, 'actions' => ['mapping.initiate']];
        $this->getJson($endpoint)->assertUnauthorized();
        $this->actingAs($administrator);
        $this->getJson($endpoint)->assertExactJson(['associated' => false, 'in_scope' => false, 'actions' => []]);
        $this->postJson($endpoint, [...$full, 'actions' => ['mapping.verify']])->assertUnprocessable();
        $this->postJson($endpoint, $full)->assertExactJson($full);
        $this->postJson($endpoint, $full)->assertExactJson($full);
        $this->assertDatabaseCount('portal_events', 1);

        $operator = User::findOrFail($id);
        $this->actingAs($operator);
        $this->getJson('/api/v1/mapping-contexts')->assertJsonCount(1);
        $this->postJson($endpoint, $full)->assertForbidden();
        foreach ([
            ['associated' => false, 'in_scope' => true, 'actions' => ['mapping.initiate']],
            ['associated' => true, 'in_scope' => false, 'actions' => ['mapping.initiate']],
            ['associated' => true, 'in_scope' => true, 'actions' => []],
        ] as $restriction) {
            $this->actingAs($administrator);
            $this->postJson($endpoint, $restriction)->assertExactJson($restriction);
            $this->actingAs($operator);
            $this->getJson('/api/v1/mapping-contexts')->assertJsonCount(0);
        }
        $this->assertDatabaseHas('customer_accounts', ['user_id' => $id, 'state' => 'Active']);
        $events = DB::table('portal_events')->where('action', 'organization_user.access_changed')->get();
        $this->assertCount(4, $events);
        $audit = json_decode($events->last()->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($tenant, $audit['tenant_id']);
        $this->assertFalse($audit['before']['in_scope']);
        $this->assertSame([], $audit['after']['actions']);

        $this->actingAs($administrator);
        $this->postJson(str_replace($tenant, Str::uuid7()->toString(), $endpoint), $full)->assertNotFound();
        $this->getJson(str_replace($shop, Str::uuid7()->toString(), $endpoint))->assertNotFound();
    }

    public function test_delegation_allows_only_scoped_changes_before_expiry_or_revocation(): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        foreach (['AA3011', 'AA3012'] as $code) {
            $id = Str::uuid7()->toString();
            DB::table('users')->insert(['id' => $id, 'code' => $code, 'email' => $code.'@example.test', 'password' => 'unused']);
            DB::table('customer_accounts')->insert(['user_id' => $id, 'tenant_id' => $tenant, 'state' => 'Active']);
        }
        $delegate = User::where('code', 'AA3011')->firstOrFail();
        $target = User::where('code', 'AA3012')->firstOrFail();
        $targetPath = "/api/v1/tenants/{$tenant}/organization-users/AA3012/business-access/{$shop}";
        $ownPath = "/api/v1/tenants/{$tenant}/organization-users/AA3011/business-access/{$shop}";
        $grantPath = "/api/v1/tenants/{$tenant}/businesses/{$shop}/access-delegations";
        $input = ['code' => 'AA3011', 'minutes' => 15, 'actions' => ['mapping.initiate']];
        $full = ['associated' => true, 'in_scope' => true, 'actions' => ['mapping.initiate']];
        $this->getJson('/api/v1/organization-access-delegations')->assertUnauthorized();
        $this->actingAs($administrator);
        $this->postJson($grantPath, $input)->assertUnprocessable();
        $this->postJson($ownPath, [...$full, 'actions' => []])->assertOk();
        $grant = $this->postJson($grantPath, $input)->assertCreated()->json('id');
        $this->actingAs($delegate);
        $this->getJson('/api/v1/organization-access-delegations')->assertJsonCount(1)->assertJsonPath('0.id', $grant);
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertJsonPath('administration', [])
            ->assertJsonPath('delegations.0.id', $grant);
        $this->getJson($targetPath)->assertOk();
        $this->postJson($targetPath, [...$full, 'actions' => ['mapping.end']])->assertForbidden();
        $this->postJson($ownPath, $full)->assertForbidden();
        $this->postJson($grantPath, [...$input, 'code' => 'AA3012'])->assertForbidden();
        $this->postJson($targetPath, $full)->assertExactJson($full);
        $this->assertDatabaseHas('portal_events', ['action' => 'organization_user.access_changed', 'actor_id' => $delegate->id, 'subject_id' => $target->id]);
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/organization-access-delegations')->assertJsonCount(0);
        $this->postJson($targetPath, [...$full, 'associated' => false])->assertForbidden();

        $this->actingAs($administrator);
        $second = $this->postJson($grantPath, $input)->assertCreated()->json('id');
        $revoke = "/api/v1/organization-access-delegations/{$second}/revoke";
        $this->postJson($revoke)->assertOk();
        $this->postJson($revoke)->assertNotFound();
        $this->actingAs($delegate);
        $this->getJson($targetPath)->assertForbidden();
        $this->assertDatabaseHas('portal_events', ['action' => 'organization_access.delegation_revoked', 'subject_id' => $second]);
    }

    public function test_roles_do_not_expand_association_scope_or_delegate_authority(): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        $roles = "/api/v1/tenants/{$tenant}/businesses/{$shop}/roles";
        $access = "/api/v1/tenants/{$tenant}/organization-users/AA4011/business-access/{$shop}";
        $delegateAccess = "/api/v1/tenants/{$tenant}/organization-users/AA4012/business-access/{$shop}";
        $mapping = '/api/v1/mapping-contexts';
        $definition = ['name' => 'Shop liaison', 'actions' => ['mapping.end']];
        $scope = ['associated' => true, 'in_scope' => true, 'actions' => []];

        foreach (['AA4011', 'AA4012'] as $code) {
            $uid = Str::uuid7()->toString();
            DB::table('users')->insert(['id' => $uid, 'code' => $code, 'email' => $code.'@example.test', 'password' => 'unused']);
            DB::table('customer_accounts')->insert(['user_id' => $uid, 'tenant_id' => $tenant, 'state' => 'Active']);
        }
        $operator = User::where('code', 'AA4011')->firstOrFail();
        $delegate = User::where('code', 'AA4012')->firstOrFail();
        $this->getJson($roles)->assertUnauthorized();
        $this->actingAs($administrator);
        $this->postJson($roles, [...$definition, 'actions' => ['mapping.verify']])->assertUnprocessable();
        $role = $this->postJson($roles, $definition)->assertCreated()->json('id');
        $this->postJson($roles, $definition)->assertConflict();
        $this->getJson($roles)->assertOk()->assertJsonPath('data.0.actions', ['mapping.end']);
        $assign = "{$roles}/{$role}/assign";
        $revoke = "{$roles}/{$role}/revoke";
        $this->postJson($assign, ['code' => 'AA9999'])->assertNotFound();
        $this->postJson($assign, ['code' => 'AA4011'])->assertExactJson(['assigned' => true]);
        $this->postJson($assign, ['code' => 'AA4011'])->assertOk();
        $this->assertSame(1, DB::table('portal_events')->where('action', 'organization_role.assigned')->count());
        $this->actingAs($operator);
        $this->getJson($mapping)->assertJsonCount(0);
        $this->postJson($assign, ['code' => 'AA4011'])->assertForbidden();

        $this->actingAs($administrator);
        $this->postJson($access, $scope)->assertOk();
        $this->postJson($delegateAccess, $scope)->assertOk();
        $this->postJson("/api/v1/tenants/{$tenant}/businesses/{$shop}/access-delegations", [
            'code' => 'AA4012', 'minutes' => 10, 'actions' => ['mapping.initiate'],
        ])->assertCreated();
        $this->actingAs($delegate);
        $this->postJson($access, [...$scope, 'in_scope' => false])->assertForbidden();

        $this->actingAs($operator);
        $this->getJson($mapping)->assertJsonCount(1)->assertJsonPath('0.action', 'mapping.end');
        $this->actingAs($administrator);
        $this->postJson($access, [...$scope, 'actions' => ['mapping.end']])->assertOk();
        $this->actingAs($operator);
        $this->getJson($mapping)->assertJsonCount(1);
        $this->actingAs($administrator);
        $this->postJson($revoke, ['code' => 'AA4011'])->assertExactJson(['assigned' => false]);
        $this->actingAs($operator);
        $this->getJson($mapping)->assertJsonCount(1);
        $this->actingAs($administrator);
        $this->postJson($access, $scope)->assertOk();
        $this->actingAs($operator);
        $this->getJson($mapping)->assertJsonCount(0);
    }

    public function test_role_approvals_enforce_parallel_and_sequential_stages(): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        $main = DB::table('portal_installation')->value('portal_id');
        foreach (['AA6111', 'AA6112', 'AA6113'] as $code) {
            $id = Str::uuid7()->toString();
            DB::table('users')->insert(['id' => $id, 'code' => $code, 'email' => $code.'@example.test', 'password' => 'unused']);
            DB::table('portal_grants')->insert(['user_id' => $id, 'business_id' => $main, 'permission' => 'organization_users.manage']);
        }
        $target = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $target, 'code' => 'AA6114', 'email' => 'target@example.test', 'password' => 'unused']);
        DB::table('customer_accounts')->insert(['user_id' => $target, 'tenant_id' => $tenant, 'state' => 'Active']);
        $base = "/api/v1/tenants/{$tenant}/businesses/{$shop}";
        $approval = "{$base}/role-approvals";
        DB::table('portal_grants')->insert(['user_id' => $administrator->id, 'business_id' => $main, 'permission' => 'organization_roles.approval_policy']);
        $this->actingAs($administrator);
        $role = $this->postJson("{$base}/roles", ['name' => 'Reviewer', 'actions' => ['mapping.confirm']])->assertCreated()->json('id');
        $assignment = "{$base}/roles/{$role}";
        $policy = [
            'operation' => 'assign',
            'stages' => [
                ['reviewers' => ['AA6111', 'AA6112', 'AA4444'], 'required' => 2],
                ['reviewers' => ['AA6113'], 'required' => 1],
            ],
        ];
        $this->postJson("{$approval}/policy", ['operation' => 'assign', 'stages' => [['reviewers' => ['AA6114'], 'required' => 1]]])->assertUnprocessable();
        $this->postJson("{$approval}/policy", ['operation' => 'assign', 'stages' => [['reviewers' => ['AA6111'], 'required' => 2]]])->assertUnprocessable();
        $this->postJson("{$approval}/policy", $policy)->assertExactJson(['stage_count' => 2]);
        $request = $this->postJson("{$assignment}/assign", ['code' => 'AA6114'])->assertAccepted()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson("{$assignment}/assign", ['code' => 'AA6114'])->assertAccepted()->assertJsonPath('id', $request);
        $this->getJson($approval)->assertOk()->assertJsonPath('data.0.id', $request);
        $decision = "{$approval}/{$request}/decision";
        $this->postJson($decision, ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->postJson("{$approval}/policy", ['operation' => 'assign', 'stages' => []])->assertOk();

        $vote = ['decision' => 'approve'];
        $this->actingAs(User::where('code', 'AA6113')->firstOrFail());
        $this->postJson("{$approval}/policy", $policy)->assertForbidden();
        $this->postJson($decision, $vote)->assertForbidden();
        $this->actingAs(User::where('code', 'AA6111')->firstOrFail());
        $this->postJson($decision, $vote)->assertOk()->assertJsonPath('stage', 0);
        $this->postJson($decision, $vote)->assertConflict();
        $this->actingAs(User::where('code', 'AA6112')->firstOrFail());
        $this->postJson($decision, $vote)->assertOk()->assertJsonPath('stage', 1);
        $this->assertDatabaseCount('organization_role_assignments', 0);
        $this->actingAs(User::where('code', 'AA6113')->firstOrFail());
        $this->postJson($decision, $vote)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($decision, $vote)->assertConflict();
        $this->assertDatabaseHas('organization_role_assignments', ['user_id' => $target, 'role_id' => $role]);

        $this->actingAs($administrator);
        $this->postJson("{$approval}/policy", ['operation' => 'revoke', 'stages' => [['reviewers' => ['AA6111'], 'required' => 1]]])->assertOk();
        $request = $this->postJson("{$assignment}/revoke", ['code' => 'AA6114'])->assertAccepted()->json('id');
        $this->actingAs(User::where('code', 'AA6111')->firstOrFail());
        $this->postJson("{$approval}/{$request}/decision", ['decision' => 'reject'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("{$approval}/{$request}/decision", $vote)->assertConflict();
        $this->assertDatabaseCount('organization_role_assignments', 1);
        $this->assertDatabaseHas('portal_events', ['action' => 'organization_role.approval_decided', 'subject_id' => $request]);
    }

    public function test_account_lifecycle_revokes_sessions_and_preserves_business_records(): void
    {
        [$tenant, $shop, $administrator] = $this->fixture();
        $operator = Str::uuid7()->toString();
        $code = 'AA7111';
        $email = 'lifecycle@example.test';
        $password = 'test-only-organization-user-password';
        DB::table('users')->insert(['id' => $operator, 'code' => $code, 'email' => $email, 'password' => Hash::make($password)]);
        DB::table('customer_accounts')->insert(['user_id' => $operator, 'tenant_id' => $tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $operator, 'business_id' => $shop, 'active' => true]);
        $path = "/api/v1/tenants/{$tenant}/organization-users/{$code}/lifecycle";
        $sessionKey = Auth::guard('web')->getName();
        $credentials = ['code' => $code, 'email' => $email, 'password' => $password];
        $this->postJson('/api/v1/login', $credentials)->assertNoContent()->assertSessionHas(ValidateOrganizationSession::VERSION, 0);
        $this->getJson('/api/v1/mapping-contexts')->assertOk();
        $this->getJson($path)->assertForbidden();
        $this->postJson('/api/v1/logout')->assertNoContent();

        $this->actingAs($administrator);
        $this->getJson($path)->assertOk()->assertJsonPath('state', 'Active');
        $this->getJson(str_replace($tenant, Str::uuid7()->toString(), $path))->assertNotFound();
        $change = ['state' => 'Suspended', 'expected_state' => 'Active', 'reason' => 'Authorization withdrawn temporarily'];
        $this->postJson($path, $change)->assertOk()->assertJsonPath('state', 'Suspended');
        $this->postJson($path, $change)->assertConflict();

        $this->actingAs(User::findOrFail($operator))->withSession([$sessionKey => $operator, ValidateOrganizationSession::VERSION => 0]);
        $this->getJson('/api/v1/mapping-contexts')->assertUnauthorized();
        $this->postJson('/api/v1/login', $credentials)->assertUnauthorized();

        $this->actingAs($administrator);
        $this->postJson($path, ['state' => 'Active', 'expected_state' => 'Suspended', 'reason' => 'Authorization restored'])->assertOk();
        $this->actingAs(User::findOrFail($operator))->withSession([$sessionKey => $operator, ValidateOrganizationSession::VERSION => 0]);
        $this->getJson('/api/v1/mapping-contexts')->assertUnauthorized();
        $this->postJson('/api/v1/login', $credentials)->assertNoContent()->assertSessionHas(ValidateOrganizationSession::VERSION, 2);
        $this->postJson('/api/v1/logout')->assertNoContent();

        $this->actingAs($administrator);
        $this->postJson($path, ['state' => 'Deactivated', 'expected_state' => 'Active', 'reason' => 'Contract ended'])->assertOk();
        $this->postJson($path, ['state' => 'Terminated', 'expected_state' => 'Deactivated', 'reason' => 'Account closure confirmed'])
            ->assertExactJson(['state' => 'Terminated', 'allowed_transitions' => []]);
        $this->postJson($path, ['state' => 'Active', 'expected_state' => 'Terminated', 'reason' => 'Invalid recovery'])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $operator, 'code' => $code]);
        $this->assertFalse(Hash::check($password, DB::table('users')->where('id', $operator)->value('password')));
        $this->assertDatabaseHas('customer_business_associations', ['user_id' => $operator, 'business_id' => $shop, 'active' => false]);
        $this->assertDatabaseHas('business_identities', ['id' => $shop, 'kind' => 'Shop']);
        $this->assertDatabaseHas('portal_events', ['action' => 'organization_user.state_changed', 'subject_id' => $operator]);
    }

    private function fixture(): array
    {
        $id = static fn (): string => Str::uuid7()->toString();
        [$main, $organization, $shop, $tenant, $admin] = [$id(), $id(), $id(), $id(), $id()];
        foreach ([$main => 'Main Portal', $organization => 'Organization', $shop => 'Shop'] as $business => $kind) {
            DB::table('business_identities')->insert([
                'id' => $business, 'kind' => $kind, 'professional_name' => $kind,
                'parent_id' => $business === $shop ? $organization : null,
            ]);
        }
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $main]);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $main]);
        DB::table('users')->insert(['id' => $admin, 'code' => 'AA4444', 'email' => 'administrator@example.test', 'password' => 'unused']);
        DB::table('portal_grants')->insert(['user_id' => $admin, 'business_id' => $main, 'permission' => 'organization_users.manage']);

        return [$tenant, $shop, User::findOrFail($admin)];
    }
}
