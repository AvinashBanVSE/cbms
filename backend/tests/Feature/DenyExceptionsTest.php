<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\DenyExceptions;
use App\Authorization\EntityRoleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Exercised D1 deny exceptions: narrower-context waiver of a matching deny. */
final class DenyExceptionsTest extends TestCase
{
    use RefreshDatabase;

    private DenyExceptions $exceptions;

    private string $organization;

    private string $tenant;

    private string $portal;

    private object $manager;

    private object $user;

    private string $shop;

    private string $role;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exceptions = new DenyExceptions;

        $this->portal = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->portal, 'kind' => 'Main Portal', 'professional_name' => 'MP']);
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $this->portal]);

        $this->organization = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->organization, 'kind' => 'Organization', 'professional_name' => 'Org']);
        $this->tenant = (string) Str::uuid7();
        DB::table('customer_tenants')->insert(['id' => $this->tenant, 'organization_id' => $this->organization, 'provider_id' => $this->portal]);

        $this->shop = (string) Str::uuid7();
        DB::table('business_identities')->insert(['id' => $this->shop, 'kind' => 'Shop', 'professional_name' => 'Shop', 'parent_id' => $this->organization]);

        $this->manager = $this->user();
        $this->user = $this->user();
        DB::table('portal_grants')->insert(['user_id' => $this->manager->id, 'business_id' => $this->portal, 'permission' => 'organization_users.manage']);
        DB::table('entity_user_scopes')->insert(['user_id' => $this->manager->id, 'business_id' => $this->portal]);

        // A governed role for the user with mapping.initiate.
        $this->role = (string) Str::uuid7();
        DB::table('organization_business_roles')->insert(['id' => $this->role, 'business_id' => $this->shop, 'name' => 'Worker', 'governed' => true, 'version' => 1]);
        DB::table('organization_role_permissions')->insert(['role_id' => $this->role, 'action' => 'mapping.initiate']);
        DB::table('organization_role_assignments')->insert(['role_id' => $this->role, 'user_id' => $this->user->id]);
        // Positive access path: association + scope + account so permissionsFor can grant.
        DB::table('customer_accounts')->insert(['user_id' => $this->user->id, 'tenant_id' => $this->tenant, 'state' => 'Active']);
        DB::table('customer_business_associations')->insert(['user_id' => $this->user->id, 'business_id' => $this->shop, 'active' => true]);
        DB::table('customer_business_scopes')->insert(['user_id' => $this->user->id, 'business_id' => $this->shop]);
        // Positive direct grant for the action (the deny will block it until the exception waives it).
        DB::table('customer_permissions')->insert(['user_id' => $this->user->id, 'business_id' => $this->shop, 'action' => 'mapping.initiate']);
    }

    private function user(): object
    {
        $this->sequence++;
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'code' => sprintf('DE%04d', $this->sequence), 'email' => 'de'.$this->sequence.'@test.invalid', 'password' => 'unused']);

        return DB::table('users')->where('id', $id)->first();
    }

    private function deny(): string
    {
        $id = (string) Str::uuid7();
        DB::table('entity_permission_denies')->insert([
            'id' => $id, 'business_id' => $this->shop, 'user_id' => $this->user->id,
            'action' => 'mapping.initiate', 'reason' => 'blocked', 'created_by' => $this->manager->id,
        ]);

        return $id;
    }

    public function test_record_scope_exception_requires_target_and_is_refused_without_it(): void
    {
        $this->deny();
        $this->expectException(HttpException::class);
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::RECORD,
            'reason' => 'x', 'expected_version' => 0,
        ]);
    }

    /**
     * Finding 5.2: a record-scoped exception waives the deny ONLY for its own target. Record A
     * is allowed, Record B and an unknown record stay denied, and the action with no target at
     * all stays denied — the exception must never widen into a whole-action waiver.
     */
    public function test_record_scope_exception_applies_only_to_its_target(): void
    {
        $this->deny();
        $recordA = (string) Str::uuid7();
        $recordB = (string) Str::uuid7();
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::RECORD, 'target_id' => $recordA,
            'reason' => 'approved for A', 'expected_version' => 0,
        ]);

        $access = new EntityRoleAccess;
        $this->assertTrue($access->allows($this->user->id, $this->shop, 'mapping.initiate', $recordA), 'waived for Record A');
        $this->assertFalse($access->allows($this->user->id, $this->shop, 'mapping.initiate', $recordB), 'Record B stays denied');
        $this->assertFalse($access->allows($this->user->id, $this->shop, 'mapping.initiate'), 'action-level (no target) stays denied');
    }

    /** A selector-scoped exception applies across the governed set, as it always did. */
    public function test_selector_scope_exception_waives_the_whole_deny(): void
    {
        $this->deny();
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::SELECTOR, 'selector' => 'self',
            'reason' => 'approved carve-out', 'expected_version' => 0,
        ]);

        $this->assertTrue((new EntityRoleAccess)->allows($this->user->id, $this->shop, 'mapping.initiate', (string) Str::uuid7()));
    }

    public function test_exception_cannot_be_self_granted_or_created_without_a_deny(): void
    {
        $this->deny();
        $this->expectException(HttpException::class);
        $this->exceptions->save($this->user->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::SELECTOR, 'selector' => 's',
            'reason' => 'self', 'expected_version' => 0,
        ]);
    }

    public function test_exception_needs_an_existing_deny(): void
    {
        $this->expectException(HttpException::class);
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::SELECTOR, 'selector' => 's',
            'reason' => 'no deny', 'expected_version' => 0,
        ]);
    }

    public function test_stale_version_is_rejected(): void
    {
        $this->deny();
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::SELECTOR, 'selector' => 's',
            'reason' => 'first', 'expected_version' => 0,
        ]);
        $this->expectException(HttpException::class);
        $this->exceptions->save($this->manager->id, $this->shop, $this->user->id, [
            'action' => 'mapping.initiate', 'scope' => DenyExceptions::SELECTOR, 'selector' => 's',
            'reason' => 'second', 'expected_version' => 0,
        ]);
    }
}
