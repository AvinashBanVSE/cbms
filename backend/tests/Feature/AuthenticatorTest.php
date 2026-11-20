<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounts\Authenticator;
use App\Http\Middleware\ValidateOrganizationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfc_6238_vector_and_replay_boundary(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC(59));
        $secret = '12345678901234567890';
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Authenticator::displaySecret($secret));
        $this->assertSame(1, Authenticator::step($secret, '287082', null));
        $this->assertNull(Authenticator::step($secret, '287082', 1));
        $this->travel(90)->seconds();
        $this->assertNull(Authenticator::step($secret, '287082', null));
    }

    public function test_enrolled_account_requires_an_authenticator_on_sign_in(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC'));
        $id = Str::uuid7()->toString();
        $login = ['code' => 'AA8901', 'email' => 'factor@example.test', 'password' => 'test-only-authenticator-password'];
        DB::table('users')->insert(['id' => $id, ...$login, 'password' => Hash::make($login['password'])]);
        $base = '/api/v1/authenticator';
        $this->getJson($base)->assertUnauthorized();
        $this->postJson('/api/v1/login', $login)->assertNoContent();
        $this->getJson($base)->assertOk()->assertExactJson(['enabled' => false]);
        $this->postJson($base.'/prepare', ['password' => 'wrong'])->assertForbidden();
        $this->postJson($base.'/confirm', ['password' => $login['password'], 'code' => '123456'])->assertConflict();
        $prepare = $this->postJson($base.'/prepare', ['password' => $login['password']])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $prepare->json('setup_key'));
        $factor = session('pending_authenticator.secret');
        $secret = Crypt::decryptString($factor);
        $first = $this->code($secret);
        $this->postJson($base.'/confirm', ['password' => $login['password'], 'code' => $first])->assertOk()->assertJsonPath('enabled', true);
        $this->assertDatabaseHas('user_authenticators', ['user_id' => $id, 'secret' => $factor, 'version' => 1]);
        $this->withSession([Authenticator::SESSION_VERSION => null]);
        $this->getJson($base)->assertUnauthorized();
        $this->postJson('/api/v1/login', $login)->assertAccepted()->assertJsonPath('stage', 'authenticator_required');
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->postJson('/api/v1/login', [...$login, 'authenticator_code' => $first])->assertUnauthorized();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            RateLimiter::hit('authenticator-login:'.$id, 60);
        }
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/login', [...$login, 'authenticator_code' => $this->code($secret)])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', [...$login, 'authenticator_code' => $this->code($secret)])->assertNoContent();
        $this->getJson($base)->assertOk()->assertJsonPath('enabled', true);
        $this->postJson($base.'/prepare', ['password' => $login['password']])->assertConflict();
        $this->travel(30)->seconds();
        $this->postJson($base.'/disable', ['password' => $login['password'], 'code' => $this->code($secret)])->assertOk()->assertJsonPath('enabled', false);
        $this->assertDatabaseHas('portal_events', ['subject_id' => $id, 'action' => 'authenticator.disabled']);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/login', $login)->assertNoContent();
    }

    public static function recoveryScopes(): array
    {
        return ['organization' => [false], 'provider' => [true]];
    }

    #[DataProvider('recoveryScopes')]
    public function test_support_replacement_revokes_old_authenticator_and_requires_a_new_one(bool $provider): void
    {
        $this->freezeTime();
        $main = Str::uuid7()->toString();
        $business = Str::uuid7()->toString();
        $scope = $provider ? $business : Str::uuid7()->toString();
        $admin = Str::uuid7()->toString();
        $member = Str::uuid7()->toString();
        $password = 'test-only-existing-factor-password';
        $email = 'replacement@example.test';
        $code = 'AA8821';
        $oldSecret = 'old-factor-test-seed';
        DB::table('business_identities')->insert([
            ['id' => $main, 'kind' => 'Main Portal', 'professional_name' => 'Portal', 'operational_state' => 'Active'],
            ['id' => $business, 'kind' => $provider ? 'Sub-Portal' : 'Organization', 'professional_name' => 'Business', 'operational_state' => 'Active'],
        ]);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $main, 'stage' => 'ready']);
        DB::table('users')->insert([
            ['id' => $admin, 'code' => 'AA8820', 'email' => 'support@example.test', 'password' => Hash::make($password)],
            ['id' => $member, 'code' => $code, 'email' => $email, 'password' => Hash::make($password)],
        ]);
        if (! $provider) {
            DB::table('customer_tenants')->insert(['id' => $scope, 'provider_id' => $main, 'organization_id' => $business]);
        }
        DB::table($provider ? 'provider_accounts' : 'customer_accounts')->insert([
            'user_id' => $member, ($provider ? 'provider_id' : 'tenant_id') => $scope, 'state' => 'Active',
        ]);
        DB::table('user_authenticators')->insert(['user_id' => $member, 'secret' => Crypt::encryptString($oldSecret), 'version' => 1]);
        $transport = new ArrayTransport;
        Mail::extend('recovery-test', static fn () => $transport);
        config(['mail.default' => 'recovery-test', 'mail.mailers.recovery-test.transport' => 'recovery-test']);
        $path = $provider ? "/api/v1/providers/{$scope}/users/recover" : "/api/v1/tenants/{$scope}/organization-users/recover";
        $request = ['code' => $code, 'reason' => 'Verified reported authenticator loss', 'replace_authenticator' => true];
        $this->actingAs(User::findOrFail($admin));
        $this->postJson($path, [...$request, 'identity_verified' => true])->assertForbidden();
        DB::table('portal_grants')->insert([
            'user_id' => $admin, 'business_id' => $main, 'permission' => $provider ? 'provider_users.manage' : 'organization_users.manage',
        ]);
        $this->postJson($path, $request)->assertUnprocessable()->assertJsonValidationErrors('identity_verified');
        $this->postJson(str_replace($scope, Str::uuid7()->toString(), $path), [...$request, 'identity_verified' => true])->assertNotFound();
        $this->postJson($path, [...$request, 'identity_verified' => true])->assertExactJson(['delivery' => 'sent']);
        $this->assertDatabaseHas('user_authenticators', ['user_id' => $member, 'secret' => null, 'reenrollment_required' => true, 'version' => 2]);
        $this->assertDatabaseHas('portal_events', ['action' => 'authenticator.replacement_started', 'actor_id' => $admin, 'subject_id' => $member]);
        $message = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame($email, $message->getTo()[0]->getAddress());
        preg_match('/Temporary password: (\w+)/', $message->getTextBody(), $temporary);

        $this->actingAs(User::findOrFail($member))->withSession([
            Auth::guard('web')->getName() => $member, ValidateOrganizationSession::VERSION => 0, Authenticator::SESSION_VERSION => 1,
        ]);
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $login = ['code' => $code, 'email' => $email, 'password' => $password];
        $this->postJson('/api/v1/login', [...$login, 'authenticator_code' => $this->code($oldSecret)])->assertUnauthorized();
        $this->postJson('/api/v1/organization-setup/verify', ['code' => $code, 'temporary_password' => $temporary[1]])->assertOk();
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
        $replacement = 'test-only-replacement-factor-password';
        $this->postJson('/api/v1/organization-setup/complete', ['password' => $replacement, 'password_confirmation' => $replacement])->assertOk();
        $login['password'] = $replacement;
        $this->postJson('/api/v1/login', $login)->assertAccepted()->assertExactJson(['stage' => 'authenticator_enrollment_required']);
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->postJson('/api/v1/authenticator/prepare', ['password' => $replacement])->assertOk();
        $newSecret = Crypt::decryptString(session('pending_authenticator.secret'));
        $this->postJson('/api/v1/authenticator/confirm', ['password' => $replacement, 'code' => $this->code($newSecret)])->assertOk();
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->assertDatabaseHas('user_authenticators', ['user_id' => $member, 'reenrollment_required' => false, 'version' => 3]);
        $this->assertDatabaseHas('portal_events', ['action' => 'authenticator.replaced', 'subject_id' => $member]);
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/login', [...$login, 'authenticator_code' => $this->code($newSecret)])->assertNoContent();
    }

    private function code(string $secret): string
    {
        $time = intdiv(now()->timestamp, 30);
        $hmac = hash_hmac('sha1', pack('N2', 0, $time), $secret, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $segment = unpack('Nvalue', substr($hmac, $offset, 4))['value'];

        return sprintf('%06d', ($segment & 0x7FFFFFFF) % 1000000);
    }

    public function test_organization_requirement_forces_isolated_enrollment_before_business_access(): void
    {
        $main = Str::uuid7()->toString();
        $organization = Str::uuid7()->toString();
        $other = Str::uuid7()->toString();
        $tenant = Str::uuid7()->toString();
        $otherTenant = Str::uuid7()->toString();
        $admin = Str::uuid7()->toString();
        $user = Str::uuid7()->toString();
        $outsider = Str::uuid7()->toString();
        foreach ([$main => 'Main Portal', $organization => 'Organization', $other => 'Organization'] as $id => $kind) {
            DB::table('business_identities')->insert(['id' => $id, 'kind' => $kind, 'professional_name' => $kind]);
        }
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $main, 'stage' => 'ready']);
        DB::table('customer_tenants')->insert([
            ['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $main],
            ['id' => $otherTenant, 'organization_id' => $other, 'provider_id' => $main],
        ]);
        $password = 'test-only-organization-mfa-password';
        foreach ([$admin => 'AA8811', $user => 'AA8812', $outsider => 'AA8813'] as $id => $code) {
            DB::table('users')->insert(['id' => $id, 'code' => $code, 'email' => $code.'@example.test', 'password' => Hash::make($password)]);
        }
        DB::table('customer_accounts')->insert([
            ['user_id' => $user, 'tenant_id' => $tenant, 'state' => 'Active'],
            ['user_id' => $outsider, 'tenant_id' => $otherTenant, 'state' => 'Active'],
        ]);
        DB::table('portal_grants')->insert(['user_id' => $admin, 'business_id' => $main, 'permission' => 'organization_users.manage']);
        $path = "/api/v1/tenants/{$tenant}/mfa-policy";
        $this->actingAs(User::findOrFail($user));
        $change = ['required' => true, 'reason' => 'Organization security policy'];
        $this->postJson($path, $change)->assertForbidden();
        $this->actingAs(User::findOrFail($admin));
        $this->postJson($path, $change)->assertExactJson(['required' => true]);
        $this->getJson("/api/v1/tenants/{$otherTenant}/mfa-policy")->assertExactJson(['required' => false]);
        $this->assertDatabaseHas('portal_events', ['action' => 'credential_policy.organization_mfa_changed', 'subject_id' => $tenant]);

        $this->actingAs(User::findOrFail($user))->withSession([
            Auth::guard('web')->getName() => $user, ValidateOrganizationSession::VERSION => 0,
        ]);
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $credentials = ['code' => 'AA8812', 'email' => 'AA8812@example.test', 'password' => $password];
        $this->postJson('/api/v1/login', [...$credentials, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/authenticator/prepare', ['password' => $password])->assertUnauthorized();
        $this->postJson('/api/v1/login', $credentials)->assertAccepted()->assertExactJson(['stage' => 'authenticator_enrollment_required']);
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->postJson('/api/v1/authenticator/prepare', ['password' => $password])->assertOk();
        $secret = Crypt::decryptString(session('pending_authenticator.secret'));
        $this->postJson('/api/v1/authenticator/confirm', ['password' => $password, 'code' => $this->code($secret)])->assertOk();
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', [...$credentials, 'authenticator_code' => $this->code($secret)])->assertNoContent();
        $this->getJson('/api/v1/workspace-access')->assertOk();
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/authenticator/disable', ['password' => $password, 'code' => $this->code($secret)])->assertConflict();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/login', ['code' => 'AA8813', 'email' => 'AA8813@example.test', 'password' => $password])->assertNoContent();
    }
}
