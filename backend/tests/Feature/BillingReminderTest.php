<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** §14.7: governed billing reminders are state-gated, lead-time-bounded and idempotent financial records. */
final class BillingReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reminder_follows_the_subscription_policy_and_is_idempotent(): void
    {
        [$base, $context] = $this->contract();

        // No policy: a reminder is refused.
        $this->postJson("{$base}/invoices/{$context['invoice']}/reminders")->assertUnprocessable();

        // Enable reminders with a 5-day lead time.
        DB::table('commercial_subscriptions')->where('id', $context['subscription'])->update([
            'billing_reminder_policy' => 'enabled', 'billing_reminder_days' => 5,
        ]);

        // Lead time not yet reached.
        $this->travelTo(CarbonImmutable::parse('2032-03-16', 'Asia/Kolkata'));
        $this->postJson("{$base}/invoices/{$context['invoice']}/reminders")->assertUnprocessable();

        // Within the lead time: record, and a same-day repeat is idempotent.
        $this->travelTo(CarbonImmutable::parse('2032-03-18', 'Asia/Kolkata'));
        $sent = $this->postJson("{$base}/invoices/{$context['invoice']}/reminders")->assertCreated();
        $reminderId = $sent->json('reminder_id');
        $this->assertSame(4, $sent->json('days_before'));
        $this->assertSame(12000, $sent->json('outstanding_paise'));
        $this->assertFalse($sent->json('duplicate'));

        $repeat = $this->postJson("{$base}/invoices/{$context['invoice']}/reminders")->assertOk()
            ->assertJsonPath('reminder_id', $reminderId)->assertJsonPath('duplicate', true);

        // A settled invoice can no longer be reminded.
        $this->postJson("{$base}/invoices/{$context['invoice']}/payments", [
            'amount_paise' => 12000, 'receipt_reference' => 'SETTLED-1',
        ])->assertCreated();
        $this->postJson("{$base}/invoices/{$context['invoice']}/reminders")->assertUnprocessable();

        $this->getJson("{$base}/invoices/{$context['invoice']}/reminders")->assertOk()
            ->assertJsonPath('invoice.status', 'Paid')->assertJsonCount(1, 'history');
        $this->assertDatabaseHas('commercial_billing_reminders', [
            'id' => $reminderId, 'invoice_id' => $context['invoice'], 'days_before' => 4, 'scheduled_on' => '2032-03-18',
        ]);
        $this->assertDatabaseHas('portal_events', [
            'subject_id' => $context['invoice'], 'action' => 'commercial.billing_reminder_sent',
        ]);
        $this->assertSame($repeat->json('reminder_id'), $reminderId);
    }

    /** A subscription cannot enable reminders without a lead time. */
    public function test_reminder_policy_requires_a_configured_lead_time(): void
    {
        [$base] = $this->contract();

        $this->postJson("{$base}/subscriptions", [
            'account_id' => DB::table('commercial_accounts')->value('id'),
            'plan_version_id' => DB::table('commercial_plan_versions')->value('id'),
            'starts_on' => '2032-03-04', 'renewal_mode' => 'manual', 'coverage_mode' => 'organization',
            'billing_reminder_policy' => 'enabled',
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
        DB::table('users')->insert(['id' => $administrator, 'code' => 'BB3911', 'email' => 'remind@example.test', 'password' => 'unused']);
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
        $plan = $this->postJson("{$base}/plans", ['name' => 'Reminder services', 'provider_id' => $provider, 'family' => 'tenant'])
            ->assertCreated()->json('id');
        $version = $this->postJson("{$base}/plans/{$plan}/versions", [
            'amount_paise' => 12000, 'term_days' => 30, 'entitlements' => ['portal.reports'],
        ])->assertCreated()->json('id');

        $subscription = $this->postJson("{$base}/subscriptions", [
            'account_id' => $account, 'plan_version_id' => $version, 'starts_on' => '2032-03-04',
            'renewal_mode' => 'manual', 'coverage_mode' => 'organization',
        ])->assertCreated()->json('id');
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
