<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounts\Authenticator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    private function code(string $secret): string
    {
        $time = intdiv(now()->timestamp, 30);
        $hmac = hash_hmac('sha1', pack('N2', 0, $time), $secret, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $segment = unpack('Nvalue', substr($hmac, $offset, 4))['value'];

        return sprintf('%06d', ($segment & 0x7FFFFFFF) % 1000000);
    }
}
