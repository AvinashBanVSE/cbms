<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UsageMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private string $portal;

    private string $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portal = $this->business('Main Portal');
        $this->administrator = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $this->portal, 'stage' => 'ready']);
        DB::table('users')->insert(['id' => $this->administrator, 'code' => 'AA4200', 'email' => 'usage@example.test', 'password' => 'unused']);
        DB::table('portal_grants')->insert(['business_id' => $this->portal, 'user_id' => $this->administrator, 'permission' => 'commercial.manage']);
        DB::table('commercial_usage_limits')->insert([
            'code' => 'api.calls', 'name' => 'API calls', 'resource' => 'api_call', 'unit' => 'count',
            'aggregation' => 'sum', 'period' => 'monthly', 'scope' => 'business',
            'limit' => 10, 'warning_threshold' => 8, 'enforcement' => 'hard_block', 'overage' => 'none', 'reset' => 'periodic',
        ]);
    }

    public function test_usage_is_measured_with_thresholds_limits_and_idempotency(): void
    {
        $business = $this->business('Organization');
        $base = '/api/v1/commercial';

        $this->getJson("{$base}/businesses/{$business}/usage")->assertUnauthorized();
        $this->actingAs(User::findOrFail($this->administrator));
        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 5])->assertCreated();

        $first = $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 4])->assertCreated();
        $this->assertSame(9, $first->json('consumed'));
        $this->assertTrue($first->json('warning'));
        $this->assertSame('warning', $first->json('state'));

        // Idempotent replay of the same client key does not double-count.
        $key = Str::uuid7()->toString();
        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 1, 'idempotency_key' => $key])->assertCreated();
        $replay = $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 1, 'idempotency_key' => $key])->assertCreated();
        $this->assertSame(10, $replay->json('consumed'));

        $this->getJson("{$base}/businesses/{$business}/usage")->assertOk()
            ->assertJsonFragment(['usage_limit' => 'api.calls', 'consumed' => 10, 'limit' => 10, 'state' => 'warning']);
    }

    public function test_a_hard_limit_is_enforced_atomically_and_does_not_mutate_data(): void
    {
        $business = $this->business('Organization');
        $base = '/api/v1/commercial';
        $this->actingAs(User::findOrFail($this->administrator));

        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 10])->assertCreated();
        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('usage');

        $this->assertSame('10', (string) DB::table('commercial_usage_windows')
            ->where(['usage_limit_code' => 'api.calls', 'business_id' => $business])->value('consumed'));
        $this->assertSame(1, DB::table('commercial_usage_events')->where('business_id', $business)->count());
    }

    public function test_usage_scope_is_isolated_per_business(): void
    {
        $business = $this->business('Organization');
        $other = $this->business('Organization');
        $base = '/api/v1/commercial';
        $this->actingAs(User::findOrFail($this->administrator));

        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $business, 'quantity' => 10])->assertCreated();
        $this->postJson("{$base}/usage", ['usage_limit' => 'api.calls', 'business_id' => $other, 'quantity' => 1])->assertCreated();

        $this->getJson("{$base}/businesses/{$other}/usage")->assertOk()
            ->assertJsonFragment(['usage_limit' => 'api.calls', 'consumed' => 1, 'state' => 'within']);
        $this->getJson("{$base}/businesses/{$business}/usage")->assertOk()
            ->assertJsonFragment(['usage_limit' => 'api.calls', 'consumed' => 10, 'state' => 'warning', 'exceeded' => false]);
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $id = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'professional_name' => $kind,
        ]);

        return $id;
    }
}
