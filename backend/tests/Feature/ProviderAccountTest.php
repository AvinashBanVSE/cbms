<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\ProviderAccess;
use App\Http\Middleware\ValidateOrganizationSession;
use App\Models\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProviderAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_sub_portal_setup_directory_scope_and_revocation(): void
    {
        $main = $this->business('Main Portal', 'CBM');
        $provider = $this->business('Sub-Portal', 'Provider A');
        $other = $this->business('Sub-Portal', 'Provider B');
        $served = $this->business('Organization', 'Served');
        $outside = $this->business('Organization', 'Outside');
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $main, 'stage' => 'ready']);
        foreach ([$served => $provider, $outside => $other] as $organization => $serviceProvider) {
            DB::table('customer_tenants')->insert(['id' => Str::uuid7()->toString(), 'organization_id' => $organization, 'provider_id' => $serviceProvider]);
        }
        $operator = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $operator, 'code' => 'AA9971', 'email' => 'operator@example.test', 'password' => 'unused']);
        $staff = "/api/v1/providers/{$provider}/users";
        $directory = '/api/v1/provider-organizations';
        $email = 'staff@example.test';
        $input = ['email' => $email, 'permission' => ProviderAccess::DIRECTORY];
        $this->postJson($staff, $input)->assertUnauthorized();
        $operatorUser = User::findOrFail($operator);
        $this->actingAs($operatorUser);
        $this->postJson($staff, $input)->assertForbidden();
        DB::table('portal_grants')->insert(['business_id' => $main, 'user_id' => $operator, 'permission' => 'provider_users.manage']);
        $this->getJson($directory)->assertForbidden();
        $this->postJson($staff, $input)->assertServiceUnavailable();
        $transport = new ArrayTransport;
        Mail::extend('memory-test', static fn () => $transport);
        config(['mail.default' => 'memory-test', 'mail.mailers.memory-test.transport' => 'memory-test']);
        $this->postJson("/api/v1/providers/{$served}/users", $input)->assertNotFound();
        $code = $this->postJson($staff, $input)->assertCreated()->assertJsonPath('delivery', 'sent')->json('user_code');
        $this->postJson($staff, $input)->assertUnprocessable();
        $this->postJson($staff.'/reissue', ['code' => $code])->assertTooManyRequests();
        $this->travel(1)->minute();
        $this->postJson("/api/v1/providers/{$other}/users/reissue", ['code' => $code])->assertNotFound();
        $this->postJson($staff.'/reissue', ['code' => $code])->assertOk()->assertJsonPath('delivery', 'sent');
        $body = $transport->messages()->last()->getOriginalMessage()->getTextBody();
        $this->assertStringNotContainsString('://', $body);
        $this->assertStringContainsString($code, $body);
        preg_match('/One-time code: (\d{6})/', $body, $otp);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $proof = ['code' => $code, 'otp' => $otp[1]];
        $this->postJson('/api/v1/organization-setup/verify', $proof)->assertOk();
        $this->postJson('/api/v1/organization-setup/verify', $proof)->assertUnprocessable();
        $this->getJson($directory)->assertUnauthorized();
        $this->mock(UncompromisedVerifier::class)->shouldReceive('verify')->andReturn(true);
        $password = 'test-only-provider-user-password';
        $this->postJson('/api/v1/organization-setup/complete', ['password' => $password, 'password_confirmation' => $password])->assertOk();
        $login = ['email' => $email, 'password' => $password, 'code' => $code];
        $this->postJson('/api/v1/login', $login)->assertNoContent();
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertJsonPath('administration', [])
            ->assertJsonPath('provider_directory.id', $provider);
        $this->getJson($directory.'?provider_id='.$other)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $served)->assertJsonMissing(['id' => $outside]);
        DB::table('customer_tenants')->where('organization_id', $served)->update(['provider_id' => $other]);
        $this->getJson($directory)->assertJsonCount(0, 'data');
        $user = User::where('code', $code)->firstOrFail();
        $this->assertDatabaseMissing('customer_accounts', ['user_id' => $user->id]);
        DB::table('portal_grants')->insert(['business_id' => $main, 'user_id' => $user->id, 'permission' => 'provider_users.manage']);
        $this->postJson($staff, $input)->assertForbidden();

        $this->actingAs($operatorUser)->withSession([Auth::guard('web')->getName() => $operator, ValidateOrganizationSession::VERSION => null]);
        $revoke = ['code' => $code, 'reason' => 'Provider authorization withdrawn'];
        $this->postJson("/api/v1/providers/{$other}/users/revoke", $revoke)->assertNotFound();
        $this->postJson($staff.'/revoke', $revoke)->assertOk()->assertJsonPath('state', 'Terminated');
        $this->postJson($staff.'/revoke', $revoke)->assertConflict();
        $this->actingAs($user)->withSession([Auth::guard('web')->getName() => $user->id, ValidateOrganizationSession::VERSION => 0]);
        $this->getJson($directory)->assertUnauthorized();
        $this->postJson('/api/v1/login', $login)->assertUnauthorized();
        $this->assertDatabaseHas('portal_events', ['subject_id' => $user->id, 'action' => 'provider_user.terminated']);
    }

    private function business(string $kind, string $name): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $id, 'kind' => $kind, 'professional_name' => $name]);

        return $id;
    }
}
