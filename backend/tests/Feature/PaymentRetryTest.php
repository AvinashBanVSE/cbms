<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** §14.8: governed payment retries are policy-defined, spaced, bounded and idempotent records. */
final class PaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retry_follows_the_policy_interval_and_maximum(): void
    {
        [$base, $context] = $this->contract();

        // No policy: a retry is refused.
        $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertUnprocessable();

        // Enable retries: up to 2 attempts, 3 days apart. The invoice is past due.
        DB::table('commercial_subscriptions')->where('id', $context['subscription'])->update([
            'payment_retry_policy' => 'enabled', 'payment_retry_max_attempts' => 2, 'payment_retry_interval_days' => 3,
        ]);

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        $first = $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertCreated();
        $retryId = $first->json('retry_id');
        $this->assertSame(1, $first->json('attempt'));
        $this->assertSame(12000, $first->json('outstanding_paise'));

        // Same-day repeat is idempotent.
        $repeat = $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertOk()
            ->assertJsonPath('retry_id', $retryId)->assertJsonPath('duplicate', true);

        // The interval has not elapsed: the second attempt is refused until 3 days later.
        $this->travelTo(CarbonImmutable::parse('2032-03-26', 'Asia/Kolkata'));
        $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertUnprocessable();

        $this->travelTo(CarbonImmutable::parse('2032-03-27', 'Asia/Kolkata'));
        $second = $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertCreated()
            ->assertJsonPath('attempt', 2);

        // The maximum has been reached.
        $this->travelTo(CarbonImmutable::parse('2032-03-30', 'Asia/Kolkata'));
        $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertUnprocessable();

        // Settling the invoice removes the retry path.
        $this->postJson("{$base}/invoices/{$context['invoice']}/payments", [
            'amount_paise' => 12000, 'receipt_reference' => 'SETTLED-RETRY',
        ])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2032-04-01', 'Asia/Kolkata'));
        $this->postJson("{$base}/invoices/{$context['invoice']}/retries")->assertUnprocessable();

        $this->getJson("{$base}/invoices/{$context['invoice']}/retries")->assertOk()
            ->assertJsonPath('invoice.status', 'Paid')->assertJsonCount(2, 'history');
        $this->assertDatabaseHas('commercial_payment_retries', [
            'id' => $retryId, 'invoice_id' => $context['invoice'], 'attempt' => 1, 'scheduled_on' => '2032-03-24',
        ]);
        $this->assertDatabaseHas('portal_events', [
            'subject_id' => $context['invoice'], 'action' => 'commercial.payment_retry_scheduled',
        ]);
        $this->assertSame($repeat->json('retry_id'), $retryId);
        $this->assertSame($second->json('attempt'), 2);
    }

    /** A subscription cannot enable retries without both a maximum and an interval. */
    public function test_retry_policy_requires_the_attempt_configuration(): void
    {
        [$base] = $this->contract();

        $this->postJson("{$base}/subscriptions", [
            'account_id' => DB::table('commercial_accounts')->value('id'),
            'plan_version_id' => DB::table('commercial_plan_versions')->value('id'),
            'starts_on' => '2032-03-04', 'renewal_mode' => 'manual', 'coverage_mode' => 'organization',
            'payment_retry_policy' => 'enabled',
        ])->assertUnprocessable();
    }

    /** @return array{0: string, 1: array{invoice:string, subscription:string}} */
    private function contract(): array
    {
        $portal = $this->business('Main Portal');
        $provider = $this->business('Sub-Portal');
        $organization = $this->business('Organization');
        $tenant = Str::uuid7()->toString();
        $administrator = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $provider]);
        DB::table('users')->insert(['id' => $administrator, 'code' => 'CC3911', 'email' => 'retry@example.test', 'password' => 'unused']);
        foreach (['commercial.manage', 'business_structure.create', 'global_business_settings.manage'] as $permission) {
            DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $administrator, 'permission' => $permission]);
        }

        $base = '/api/v1/commercial';
        $this->actingAs(User::findOrFail($administrator));
        $this->travelTo(CarbonImmutable::parse('2032-03-10', 'Asia/Kolkata'));
        $account = $this->postJson("{$base}/accounts", [
            'tenant_id' => $tenant, 'provider_id' => $provider, 'billing_party' => 'Contracting entity',
        ])->assertCreated()->json('id');
        $this->postJson("{$base}/entitlements", ['code' => 'portal.reports', 'name' => 'Reports'])->assertCreated();
        $plan = $this->postJson("{$base}/plans", ['name' => 'Retry services', 'provider_id' => $provider, 'family' => 'tenant'])
            ->assertCreated()->json('id');
        $version = $this->postJson("{$base}/plans/{$plan}/versions", [
            'amount_paise' => 12000, 'term_days' => 30, 'entitlements' => ['portal.reports'],
        ])->assertCreated()->json('id');

        $subscription = $this->postJson("{$base}/subscriptions", [
            'account_id' => $account, 'plan_version_id' => $version, 'starts_on' => '2032-03-04',
            'renewal_mode' => 'manual', 'coverage_mode' => 'organization',
        ])->assertCreated()->json('id');
        // A term invoice due 2032-03-22 becomes past due after that date.
        $invoice = $this->postJson("{$base}/invoices", ['subscription_id' => $subscription, 'due_on' => '2032-03-22'])
            ->assertCreated()->json('id');

        return [$base, ['invoice' => $invoice, 'subscription' => $subscription]];
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
