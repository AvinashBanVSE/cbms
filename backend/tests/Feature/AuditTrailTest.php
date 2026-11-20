<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_trail_requires_its_own_grant_and_records_paginated_reads(): void
    {
        $path = '/api/v1/audit-trail';
        $this->getJson($path)->assertUnauthorized();
        $portal = Str::uuid7()->toString();
        DB::table('business_identities')->insert(['id' => $portal, 'kind' => 'Main Portal', 'professional_name' => 'Portal']);
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        $user = Str::uuid7()->toString();
        DB::table('users')->insert(['id' => $user, 'code' => 'AA1138', 'email' => 'audit@example.test', 'password' => 'unused']);
        $this->actingAs(User::findOrFail($user));
        $this->getJson($path)->assertForbidden();
        DB::table('portal_grants')->insert(['user_id' => $user, 'business_id' => $portal, 'permission' => 'audit_trail.view']);
        $this->getJson('/api/v1/services/categories')->assertForbidden();
        $this->getJson($path.'?subject_id=unknown')->assertUnprocessable();
        $this->getJson($path.'?before_id='.Str::uuid7())->assertNotFound();

        DB::table('business_time_presentation')->insert([
            'business_id' => $portal, 'clock' => '24-hour', 'version' => 1,
        ]);
        DB::table('business_presentation_preferences')->insert([
            'business_id' => $portal, 'setting' => 'date_format', 'value' => 'year-month-day', 'version' => 1,
        ]);
        $subject = Str::uuid7()->toString();
        $ids = [];
        for ($index = 0; $index < 31; $index++) {
            $ids[] = Str::uuid7()->toString();
            DB::table('portal_events')->insert([
                'id' => $ids[$index], 'subject_id' => $subject, 'action' => 'business.created', 'actor_id' => $user,
                'recorded_at' => '2026-11-20 00:00:00', 'details' => '{"internal":"private"}',
            ]);
        }
        rsort($ids, SORT_STRING);
        $filtered = $path.'?action=business.created&subject_id='.$subject;
        $first = $this->getJson($filtered)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(30, 'data')->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonPath('data.0.recorded_at', '2026-11-20T00:00:00.000000Z')
            ->assertJsonPath('presentation.clock', '24-hour')->assertJsonPath('presentation.date_format', 'year-month-day')
            ->assertJsonMissingPath('data.0.details')->assertJsonPath('next_before_id', $ids[29]);
        $this->getJson($filtered.'&before_id='.$first->json('next_before_id'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[30])->assertJsonPath('next_before_id', null);
        $this->assertDatabaseHas('portal_events', ['action' => 'audit_trail.viewed', 'actor_id' => $user, 'subject_id' => $subject]);
        $this->assertSame(2, DB::table('portal_events')->where('action', 'audit_trail.viewed')->count());
    }
}
