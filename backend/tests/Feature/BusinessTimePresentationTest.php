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

    public function test_delegated_editor_can_change_only_the_authorized_organization_clock(): void
    {
        $portal = $this->business('Main Portal');
        $organization = $this->business('Organization');
        $other = $this->business('Organization');
        $shop = $this->business('Shop', $organization);
        $tenant = Str::uuid7()->toString();
        $mainUser = Str::uuid7()->toString();
        $editor = Str::uuid7()->toString();
        DB::table('business_identities')->where('id', $organization)->update(['operational_state' => 'Active']);
        DB::table('portal_installation')->where('id', 1)->update(['stage' => 'ready', 'portal_id' => $portal]);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $portal]);
        foreach ([$mainUser => 'AA8882', $editor => 'AA8883'] as $id => $code) {
            DB::table('users')->insert(['id' => $id, 'email' => $code.'@example.test', 'code' => $code, 'password' => 'unused']);
        }
        DB::table('portal_grants')->insert(['user_id' => $mainUser, 'business_id' => $portal, 'permission' => 'global_business_settings.manage']);
        DB::table('customer_accounts')->insert(['user_id' => $editor, 'tenant_id' => $tenant, 'state' => 'Active']);
        $binding = ['user_id' => $editor, 'business_id' => $organization];
        DB::table('customer_business_associations')->insert([...$binding, 'active' => true]);
        $base = '/api/v1/global-business-settings/time-presentation';
        $settings = '/api/v1/organization-time-presentation';
        $access = $base.'/'.$organization.'/access';
        $grant = ['code' => 'AA8883', 'enabled' => true];
        $clock = ['clock' => '12-hour', 'expected_version' => 1];

        $this->actingAs(User::findOrFail($editor));
        $this->getJson($settings)->assertForbidden();
        $this->postJson($access, $grant)->assertForbidden();
        $this->actingAs(User::findOrFail($mainUser));
        $this->postJson($base.'/'.$other.'/access', $grant)->assertNotFound();
        $this->postJson($access, $grant)->assertOk()->assertJsonPath('enabled', true);
        $this->postJson($base.'/'.$organization, [
            'clock' => null, 'override_allowed' => true, 'locked' => false, 'expected_version' => 0,
        ])->assertOk();
        $this->actingAs(User::findOrFail($editor));
        $this->getJson($settings)->assertForbidden();
        DB::table('customer_business_scopes')->insert($binding);
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertJsonPath('organization_time_presentation.id', $organization);
        $this->getJson($settings)->assertOk()->assertJsonPath('business_id', $organization)->assertJsonPath('can_manage', true);
        $this->getJson($base.'/'.$other)->assertForbidden();
        $this->postJson($settings, [...$clock, 'locked' => true])->assertUnprocessable();
        $this->postJson($settings, $clock)->assertOk()->assertJsonPath('local.version', 2);
        $this->assertDatabaseMissing('business_time_presentation', ['business_id' => $other]);
        $this->assertDatabaseMissing('business_time_presentation', ['business_id' => $shop]);
        $this->assertDatabaseHas('portal_events', ['action' => 'global_business_settings.time_presentation_changed', 'actor_id' => $editor, 'subject_id' => $organization]);

        $this->actingAs(User::findOrFail($mainUser));
        $calendar = '/api/v1/global-business-settings/calendar-presentation';
        $organizationDate = '/api/v1/organization-calendar-presentation/date_format';
        $this->postJson($calendar.'/unexpected', ['date_format' => 'year-month-day'])->assertNotFound();
        $this->postJson($calendar.'/date_format/'.$organization, [
            'date_format' => null, 'override_allowed' => true, 'locked' => false, 'expected_version' => 0,
        ])->assertOk();
        $this->postJson($calendar.'/week_start', [
            'week_start' => 'monday', 'override_allowed' => false, 'locked' => true, 'expected_version' => 0,
        ])->assertOk();
        $this->actingAs(User::findOrFail($editor));
        $this->getJson($organizationDate)->assertForbidden();
        $this->actingAs(User::findOrFail($mainUser));
        $this->postJson($calendar.'/date_format/'.$organization.'/access', $grant)->assertOk();
        $this->actingAs(User::findOrFail($editor));
        $this->getJson('/api/v1/workspace-access')->assertOk()->assertJsonPath('organization_time_presentation.settings', ['clock', 'date_format']);
        $this->postJson($organizationDate, ['date_format' => 'yyyy', 'expected_version' => 1])->assertUnprocessable();
        $this->postJson($organizationDate, ['date_format' => 'year-month-day', 'expected_version' => 1])
            ->assertOk()->assertJsonPath('effective.date_format', 'year-month-day')->assertJsonPath('effective.source', 'organization');
        $this->getJson('/api/v1/organization-calendar-presentation/week_start')->assertForbidden();
        $this->actingAs(User::findOrFail($mainUser));
        DB::table('portal_grants')->insert(['user_id' => $mainUser, 'business_id' => $portal, 'permission' => 'global_business_settings.view']);
        $this->getJson($calendar.'/date_format/'.$shop)->assertOk()->assertJsonPath('effective.date_format', 'year-month-day');
        $this->getJson($calendar.'/date_format/'.$other)->assertOk()->assertJsonPath('effective.date_format', null);
        $this->getJson($calendar.'/week_start/'.$shop)->assertOk()->assertJsonPath('effective.week_start', 'monday')
            ->assertJsonPath('upstream_lock', 'platform');

        $this->postJson($base, ['clock' => '24-hour', 'override_allowed' => false, 'locked' => true, 'expected_version' => 0])->assertOk();
        $this->actingAs(User::findOrFail($editor));
        $this->getJson($settings)->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('effective.clock', '24-hour');
        $this->getJson($organizationDate)->assertOk()->assertJsonPath('can_manage', true)
            ->assertJsonPath('effective.date_format', 'year-month-day');
        $this->postJson($settings, [...$clock, 'expected_version' => 2])->assertForbidden();
        $this->actingAs(User::findOrFail($mainUser));
        $this->postJson($access, [...$grant, 'enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->actingAs(User::findOrFail($editor));
        $this->getJson($settings)->assertForbidden();
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
