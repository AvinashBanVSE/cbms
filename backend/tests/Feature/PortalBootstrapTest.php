<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PortalBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'installation-test-secret-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.setup_digest' => hash('sha256', self::CODE)]);
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
    }

    public function test_setup_requires_deployment_secret(): void
    {
        $this->postJson('/api/v1/installation/setup')->assertForbidden();
        $this->assertDatabaseCount('business_identities', 0);
    }

    public function test_owner_can_sign_in_and_read_catalogue_after_setup(): void
    {
        config(['session.driver' => 'database']);
        $headers = ['X-Setup-Code' => self::CODE];
        $portal = ['legal_name' => 'Portal Operator', 'professional_name' => 'CBM'];
        $credentials = ['email' => 'owner@example.test', 'password' => 'Only-for-automated-tests-879'];
        $this->postJson('/api/v1/installation/owner', [], $headers)->assertConflict();
        $this->postJson('/api/v1/installation/setup', $portal, $headers)->assertCreated();
        $this->postJson('/api/v1/installation/setup', $portal, $headers)->assertConflict();
        $this->postJson('/api/v1/installation/owner', [
            'name' => 'Owner', ...$credentials, 'password' => str_repeat('अ', 25),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseCount('users', 0);
        $owner = $this->postJson('/api/v1/installation/owner', ['name' => 'Owner', ...$credentials], $headers)->assertCreated();
        $this->assertMatchesRegularExpression('/^[A-Z]{2}[0-9]{4}$/', $owner->json('user_code'));
        $this->getJson('/api/v1/services/categories')->assertUnauthorized();
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->postJson('/api/v1/installation/owner', [], $headers)->assertConflict();
        $this->postJson('/api/v1/login', ['code' => $owner->json('user_code'), ...$credentials, 'password' => 'invalid'])->assertUnauthorized();
        $this->assertDatabaseHas('portal_events', ['action' => 'login.failed']);
        $this->postJson('/api/v1/login', ['code' => $owner->json('user_code'), ...$credentials])->assertNoContent();
        $this->assertDatabaseHas('sessions', [
            'user_id' => DB::table('users')->where('code', $owner->json('user_code'))->value('id'),
        ]);
        $this->getJson('/api/v1/services/categories')->assertOk()->assertJsonCount(15);
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['administration' => ['services', 'businesses', 'identities', 'users', 'commercial', 'provider_users', 'audit'], 'mapping_contexts' => [], 'delegations' => []]);
        $this->getJson('/api/v1/audit-trail')->assertOk();
        $service = ['category' => 'B1', 'name' => 'Delivery', 'purpose' => 'Deliver approved messages', 'scopes' => ['Tenant-scoped']];
        $id = $this->postJson('/api/v1/services', $service)->assertCreated()->json('id');
        $this->getJson('/api/v1/services')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/services', $service)->assertConflict();
        $this->postJson("/api/v1/services/{$id}/capabilities", ['name' => 'Email', 'purpose' => 'Deliver email'])->assertCreated();
        $this->getJson("/api/v1/services/{$id}/capabilities")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Email');
        $this->getJson('/api/v1/services/018f0000-0000-7000-8000-000000000000/capabilities')->assertNotFound();
        $other = $this->postJson('/api/v1/services', [...$service, 'name' => 'Other'])->assertCreated()->json('id');
        $this->getJson("/api/v1/services/{$other}/capabilities")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonPath('data.0.kind', 'Main Portal');
        $this->postJson('/api/v1/businesses', [
            'kind' => 'Organization', 'legal_name' => 'Customer Ltd', 'professional_name' => 'Customer',
            'provider_id' => DB::table('portal_installation')->value('portal_id'), 'new_owner_name' => 'Customer owner',
        ])->assertCreated();
        DB::table('portal_grants')->where('permission', '!=', 'commercial.manage')->delete();
        $this->getJson('/api/v1/workspace-access')->assertOk()
            ->assertExactJson(['administration' => ['commercial'], 'mapping_contexts' => [], 'delegations' => []]);
        $this->getJson('/api/v1/commercial?section=accounts')->assertOk();
        $this->getJson('/api/v1/audit-trail')->assertForbidden();
        $this->getJson("/api/v1/services/{$id}/capabilities")->assertForbidden();
        $this->postJson('/api/v1/services', $service)->assertForbidden();
        $this->getJson('/api/v1/services/categories')->assertForbidden();
        DB::table('portal_grants')->delete();
        $this->getJson('/api/v1/workspace-access')->assertOk()
            ->assertExactJson(['administration' => [], 'mapping_contexts' => [], 'delegations' => []]);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseHas('portal_events', ['action' => 'login.succeeded']);
        $this->assertDatabaseHas('portal_events', ['action' => 'logout']);
        $this->getJson('/api/v1/services/categories')->assertUnauthorized();
    }
}
