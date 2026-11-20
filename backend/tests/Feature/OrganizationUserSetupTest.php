<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
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
