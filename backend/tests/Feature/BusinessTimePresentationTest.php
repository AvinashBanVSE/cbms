<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessTimePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_settings_respect_hierarchy_locks_versions_and_authority(): void
    {
        $portal = $this->business('Main Portal');
        $organization = $this->business('Organization');
        $shop = $this->business('Shop', $organization);
        $distributor = $this->business('Distributor', $organization);
        $otherOrganization = $this->business('Organization');
        $provider = $this->business('Sub-Portal');
        $id = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $portal]);
        DB::table('users')->insert(['id' => $id, 'email' => 'settings@example.test', 'code' => 'AA8881', 'password' => 'unused']);
        $base = '/api/v1/global-business-settings/time-presentation';
        $command = ['clock' => '24-hour', 'expected_version' => 0, 'override_allowed' => false, 'locked' => false];

        $this->getJson($base)->assertUnauthorized();
        $this->actingAs(User::findOrFail($id));
        $this->getJson($base)->assertForbidden();
        DB::table('portal_grants')->insert([
            'user_id' => $id, 'business_id' => $portal, 'permission' => 'global_business_settings.view',
        ]);
        $this->getJson($base)->assertOk()->assertJsonPath('effective.source', 'locale_default')->assertJsonPath('can_manage', false);
        $this->postJson($base, $command)->assertForbidden();
        $this->getJson($base.'/'.$provider)->assertNotFound();
        DB::table('portal_grants')->insert([
            'user_id' => $id, 'business_id' => $portal, 'permission' => 'global_business_settings.manage',
        ]);
        $this->postJson($base, [...$command, 'clock' => '25-hour'])->assertUnprocessable();
        $this->postJson($base, $command)->assertOk()->assertJsonPath('local.version', 1)->assertJsonPath('can_manage', true);
        $this->postJson($base, $command)->assertConflict();
        $this->postJson($base, [...$command, 'expected_version' => 1])->assertOk()->assertJsonPath('local.version', 1);
        $this->getJson($base.'/'.$shop)->assertOk()->assertJsonPath('effective.clock', '24-hour')->assertJsonPath('effective.source', 'platform');
        $this->postJson($base.'/'.$shop, [...$command, 'clock' => '12-hour'])->assertUnprocessable();

        $this->postJson($base.'/'.$organization, [...$command, 'clock' => '12-hour', 'override_allowed' => true, 'locked' => true])->assertOk();
        $this->getJson($base.'/'.$shop)->assertOk()->assertJsonPath('effective.source', 'organization')
            ->assertJsonPath('effective.clock', '12-hour')->assertJsonPath('upstream_lock', 'organization');
        $this->getJson($base.'/'.$otherOrganization)->assertOk()->assertJsonPath('effective.clock', '24-hour');
        $this->postJson($base.'/'.$shop, [...$command, 'override_allowed' => true])->assertUnprocessable();
        $this->postJson($base.'/'.$organization, [...$command, 'clock' => '12-hour', 'override_allowed' => true, 'expected_version' => 1])->assertOk();
        $this->postJson($base.'/'.$shop, [...$command, 'override_allowed' => true])->assertOk()->assertJsonPath('effective.source', 'business');
        $this->getJson($base.'/'.$distributor)->assertOk()->assertJsonPath('effective.clock', '12-hour');

        $this->postJson($base, [...$command, 'expected_version' => 1, 'locked' => true])->assertOk();
        $this->getJson($base.'/'.$shop)->assertOk()->assertJsonPath('effective.source', 'platform')->assertJsonPath('upstream_lock', 'platform');
        $this->postJson($base.'/'.$organization, [...$command, 'override_allowed' => true, 'expected_version' => 2])->assertUnprocessable();
        $this->postJson($base, [...$command, 'expected_version' => 2])->assertOk();
        $this->getJson($base.'/'.$shop)->assertOk()->assertJsonPath('effective.source', 'business');
        $this->assertSame(6, DB::table('portal_events')->where('action', 'global_business_settings.time_presentation_changed')->count());
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $id, 'kind' => $kind, 'professional_name' => $kind, 'parent_id' => $parent,
        ]);

        return $id;
    }
}
