<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LoginIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-only-strong-password-123';

    private function user(string $code, string $email): string
    {
        $id = Str::uuid7()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'code' => $code,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
        ]);

        return $id;
    }

    private function login(string $code, string $identifier, int $status = 204): void
    {
        $this->postJson('/api/v1/login', [
            'code' => $code,
            'identifier' => $identifier,
            'password' => self::PASSWORD,
        ])->assertStatus($status);
    }

    public function test_legacy_email_client_and_new_email_identifier_work_by_default(): void
    {
        $this->user('AA1234', 'owner@example.test');

        $this->postJson('/api/v1/login', [
            'code' => 'AA1234', 'email' => 'owner@example.test', 'password' => self::PASSWORD,
        ])->assertNoContent();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->login('AA1234', 'OWNER@EXAMPLE.TEST');
    }

    public function test_user_can_enable_username_and_mobile_and_disable_email(): void
    {
        $id = $this->user('BB1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));

        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => 'wrong',
            'username' => 'cbm.owner',
            'mobile_number' => '9876543210',
            'username_login_enabled' => true,
            'mobile_login_enabled' => true,
            'email_login_enabled' => false,
        ])->assertForbidden();

        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD,
            'username' => 'CBM.Owner',
            'mobile_number' => '9876543210',
            'username_login_enabled' => true,
            'mobile_login_enabled' => true,
            'email_login_enabled' => false,
        ])->assertOk()->assertJsonPath('username', 'cbm.owner')
            ->assertJsonPath('mobile_number', '9876543210')
            ->assertJsonPath('email_login_enabled', false);

        $this->getJson('/api/v1/me/login-identifiers')
            ->assertOk()->assertJsonPath('username_login_enabled', true);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->login('BB1234', 'owner@example.test', 401);
        $this->login('BB1234', 'CBM.OWNER');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->login('BB1234', '9876543210');
    }

    public function test_disabled_aliases_do_not_authenticate_even_when_stored(): void
    {
        $id = $this->user('CC1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));
        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD,
            'username' => 'alternate',
            'mobile_number' => '9876543210',
        ])->assertOk();
        $this->postJson('/api/v1/logout')->assertNoContent();

        $this->login('CC1234', 'alternate', 401);
        $this->login('CC1234', '9876543210', 401);
        $this->login('CC1234', 'owner@example.test');
    }

    public function test_user_cannot_disable_all_methods_or_enable_missing_alias(): void
    {
        $id = $this->user('DD1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));

        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD, 'email_login_enabled' => false,
        ])->assertUnprocessable();

        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD, 'username_login_enabled' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $id,
            'email_login_enabled' => true,
            'username_login_enabled' => false,
        ]);
    }

    public function test_code_scopes_alias_even_if_two_users_have_the_same_alias(): void
    {
        $first = $this->user('EE1234', 'first@example.test');
        $second = $this->user('FF1234', 'second@example.test');
        DB::table('users')->whereIn('id', [$first, $second])->update([
            'username' => 'shared.name', 'username_login_enabled' => true,
        ]);
        $this->login('EE1234', 'shared.name');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->login('FF1234', 'shared.name');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->login('GG1234', 'shared.name', 401);
    }

    public function test_preferences_need_an_authenticated_session_and_valid_indian_mobile(): void
    {
        $this->getJson('/api/v1/me/login-identifiers')->assertUnauthorized();
        $this->putJson('/api/v1/me/login-identifiers', ['password' => self::PASSWORD])->assertUnauthorized();

        $id = $this->user('HH1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));
        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD, 'mobile_number' => '12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('mobile_number');
        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD, 'username' => '9876543210',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }
    public function test_reserved_username_is_rejected(): void
    {
        DB::table('reserved_usernames')->insert([
            'username' => 'administrator',
            'reserved_at' => now(),
        ]);
        $id = $this->user('II1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));

        $this->putJson('/api/v1/me/login-identifiers', [
            'password' => self::PASSWORD,
            'username' => 'Administrator',
            'username_login_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_reserved_usernames_management_requires_main_portal_authorization(): void
    {
        $id = $this->user('JJ1234', 'owner@example.test');
        $this->actingAs(User::findOrFail($id));
        $this->getJson('/api/v1/reserved-usernames')->assertForbidden();
        $this->postJson('/api/v1/reserved-usernames', [
            'username' => 'administrator',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/reserved-usernames/administrator')->assertForbidden();
        $this->assertDatabaseCount('reserved_usernames', 0);
    }

    public function test_authorized_main_portal_staff_can_manage_reserved_names(): void
    {
        $staff = $this->user('KK1234', 'staff@example.test');
        $portal = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'CBM',
        ]);
        DB::table('portal_installation')->where('id', 1)->update([
            'portal_id' => $portal, 'stage' => 'ready',
        ]);
        DB::table('portal_grants')->insert([
            'user_id' => $staff, 'business_id' => $portal, 'permission' => 'organization_users.manage',
        ]);
        $this->actingAs(User::findOrFail($staff));

        $this->postJson('/api/v1/reserved-usernames', [
            'username' => 'Administrator',
        ])->assertCreated()->assertJsonPath('username', 'administrator');
        $this->getJson('/api/v1/reserved-usernames')
            ->assertOk()->assertJsonPath('data.0', 'administrator');
        $this->postJson('/api/v1/reserved-usernames', [
            'username' => 'administrator',
        ])->assertUnprocessable();
        $this->deleteJson('/api/v1/reserved-usernames/administrator')->assertNoContent();
        $this->assertDatabaseCount('reserved_usernames', 0);
    }

    public function test_alias_login_preserves_authenticator_challenge(): void
    {
        $user = $this->user('LL1234', 'factor@example.test');
        DB::table('users')->where('id', $user)->update([
            'username' => 'factor.user', 'username_login_enabled' => true,
        ]);
        DB::table('user_authenticators')->insert([
            'user_id' => $user,
            'secret' => \Illuminate\Support\Facades\Crypt::encryptString('test-only-authenticator-key'),
            'version' => 1,
        ]);

        $this->postJson('/api/v1/login', [
            'code' => 'LL1234', 'identifier' => 'factor.user', 'password' => self::PASSWORD,
        ])->assertAccepted()->assertJsonPath('stage', 'authenticator_required');
        $this->getJson('/api/v1/workspace-access')->assertUnauthorized();
    }

}
