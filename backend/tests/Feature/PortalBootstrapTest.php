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
        $this->postJson('/api/v1/installation/owner', [], $headers)->assertConflict();
        $this->postJson('/api/v1/login', ['code' => $owner->json('user_code'), ...$credentials, 'password' => 'invalid'])->assertUnauthorized();
        $this->assertDatabaseHas('portal_events', ['action' => 'login.failed']);
        $this->postJson('/api/v1/login', ['code' => $owner->json('user_code'), ...$credentials])->assertNoContent();
        $this->getJson('/api/v1/services/categories')->assertOk()->assertJsonCount(15);
        $service = ['category' => 'B1', 'name' => 'Delivery', 'purpose' => 'Deliver approved messages', 'scopes' => ['Tenant-scoped']];
        $id = $this->postJson('/api/v1/services', $service)->assertCreated()->json('id');
        $this->getJson('/api/v1/services')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/services', $service)->assertConflict();
        $this->postJson("/api/v1/services/{$id}/capabilities", ['name' => 'Email', 'purpose' => 'Deliver email'])->assertCreated();
        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonPath('data.0.kind', 'Main Portal');
        $this->postJson('/api/v1/businesses', [
            'kind' => 'Organization', 'legal_name' => 'Customer Ltd', 'professional_name' => 'Customer',
            'provider_id' => DB::table('portal_installation')->value('portal_id'), 'new_owner_name' => 'Customer owner',
        ])->assertCreated();
        DB::table('portal_grants')->delete();
        $this->postJson('/api/v1/services', $service)->assertForbidden();
        $this->getJson('/api/v1/services/categories')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseHas('portal_events', ['action' => 'login.succeeded']);
        $this->assertDatabaseHas('portal_events', ['action' => 'logout']);
        $this->getJson('/api/v1/services/categories')->assertUnauthorized();
    }
}
