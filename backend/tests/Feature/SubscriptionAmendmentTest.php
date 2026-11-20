<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SubscriptionAmendmentTest extends TestCase
{
    use RefreshDatabase;

    /** §14.9: a Plan change is explicit, effective-dated, and preserves before/after terms. */
    public function test_an_immediate_upgrade_is_effective_dated_and_preserves_the_prior_term(): void
    {
        [$base, $context] = $this->contract();
        $nextVersion = $this->version($context['plan'], $context['provider'], 20000, 30);

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        $amended = $this->postJson("{$base}/subscriptions/{$context['subscription']}/amend", [
            'plan_version_id' => $nextVersion, 'change_type' => 'upgrade', 'timing' => 'immediate',
            'effective_date' => '2032-03-24', 'reason' => 'Add reporting capacity',
        ])->assertCreated();

        $amendmentId = $amended->json('amendment_id');
        // The change is effective now and the billing calculation reference is preserved.
        $this->assertStringStartsWith('2032-03-24', (string) $amended->json('effective_at'));
        $this->assertSame('upgrade', $amended->json('change_type'));
        // On Asia/Kolkata business dates 10 of 30 days remain, so the daily upgrade proration is (20000-12000)*10/30.
        $this->assertSame(2667, $amended->json('charge_paise'));
        $this->assertSame(0, $amended->json('credit_paise'));
        $this->assertDatabaseHas('commercial_subscription_amendments', [
            'id' => $amendmentId, 'subscription_id' => $context['subscription'], 'amended_from_id' => $context['subscription'],
            'change_type' => 'upgrade', 'timing' => 'immediate', 'proration_basis' => 'daily',
        ]);
        $this->assertDatabaseHas('commercial_invoices', ['amendment_id' => $amendmentId]);

        // The prior term's version remains readable through the amendment link.
        $current = DB::table('commercial_subscriptions')->where('id', $context['subscription'])->first();
        $this->assertSame($nextVersion, $current->plan_version_id);
        $this->assertDatabaseHas('commercial_subscription_amendments', ['id' => $amendmentId]);
    }

    /** A next-cycle change keeps the current term on its plan and takes effect at term end. */
    public function test_a_next_cycle_change_defers_effect_to_the_end_of_the_term(): void
    {
        [$base, $context] = $this->contract();
        $nextVersion = $this->version($context['plan'], $context['provider'], 20000, 30);

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        $amended = $this->postJson("{$base}/subscriptions/{$context['subscription']}/amend", [
            'plan_version_id' => $nextVersion, 'change_type' => 'upgrade', 'timing' => 'next_cycle',
            'reason' => 'Defer to renewal',
        ])->assertCreated();

        // No proration is raised until the change takes effect.
        $this->assertSame(0, $amended->json('charge_paise'));
        $this->assertStringStartsWith('2032-04-03', (string) $amended->json('effective_at'));
        $current = DB::table('commercial_subscriptions')->where('id', $context['subscription'])->first();
        $this->assertSame($context['version'], $current->plan_version_id);
        $this->assertDatabaseMissing('commercial_invoices', ['amendment_id' => $amended->json('amendment_id')]);
    }

    /** A downgrade credits the unused remainder rather than charging it. */
    public function test_a_downgrade_immediately_credits_the_unused_remainder(): void
    {
        [$base, $context] = $this->contract();
        $lowerVersion = $this->version($context['plan'], $context['provider'], 6000, 30);

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        $amended = $this->postJson("{$base}/subscriptions/{$context['subscription']}/amend", [
            'plan_version_id' => $lowerVersion, 'change_type' => 'downgrade', 'timing' => 'immediate',
            'effective_date' => '2032-03-24', 'reason' => 'Reduce scope',
        ])->assertCreated();

        $this->assertSame('downgrade', $amended->json('change_type'));
        // On Asia/Kolkata business dates 10 of 30 days remain, so the daily downgrade credit is (12000-6000)*10/30.
        $this->assertSame(2000, $amended->json('credit_paise'));
        $this->assertSame(0, $amended->json('charge_paise'));
    }

    /** An amendment from another provider's version, or on a non-active term, is denied. */
    public function test_cross_provider_and_non_active_terms_cannot_be_amended(): void
    {
        [$base, $context] = $this->contract();

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        // A version published by a different provider is rejected.
        $otherPlan = $this->plan($context['portal']);
        $foreignVersion = $this->version($otherPlan, $context['portal'], 10000, 30);
        $this->postJson("{$base}/subscriptions/{$context['subscription']}/amend", [
            'plan_version_id' => $foreignVersion, 'change_type' => 'upgrade', 'timing' => 'immediate',
            'effective_date' => '2032-03-24', 'reason' => 'Foreign plan',
        ])->assertUnprocessable();

        // A suspended term cannot be amended.
        DB::table('commercial_subscriptions')->where('id', $context['subscription'])->update(['state' => 'Suspended']);
        $nextVersion = $this->version($context['plan'], $context['provider'], 20000, 30);
        $this->postJson("{$base}/subscriptions/{$context['subscription']}/amend", [
            'plan_version_id' => $nextVersion, 'change_type' => 'upgrade', 'timing' => 'immediate',
            'effective_date' => '2032-03-24', 'reason' => 'Suspended',
        ])->assertConflict();

        $this->assertDatabaseMissing('commercial_subscription_amendments', ['subscription_id' => $context['subscription']]);
    }

    /** @return array{0: string, 1: array{portal:string, provider:string, organization:string, plan:string, version:string, account:string, subscription:string, tenant:string}} */
    private function contract(): array
    {
        $portal = $this->business('Main Portal');
        $provider = $this->business('Sub-Portal');
        $organization = $this->business('Organization');
        $child = $this->business('Shop', $organization);
        $tenant = Str::uuid7()->toString();
        $administrator = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $provider]);
        DB::table('users')->insert(['id' => $administrator, 'code' => 'AA3911', 'email' => 'amend@example.test', 'password' => 'unused']);
        foreach (['commercial.manage', 'business_structure.create', 'global_business_settings.manage'] as $permission) {
            DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $administrator, 'permission' => $permission]);
        }

        $base = '/api/v1/commercial';
        $this->actingAs(User::findOrFail($administrator));
        $this->travelTo(CarbonImmutable::parse('2032-03-22', 'Asia/Kolkata'));
        $this->postJson("{$base}/entitlements", ['code' => 'portal.reports', 'name' => 'Reports'])
            ->assertCreated();
        $account = $this->postJson("{$base}/accounts", [
            'tenant_id' => $tenant, 'provider_id' => $provider, 'billing_party' => 'Contracting entity',
        ])->assertCreated()->json('id');

        $plan = $this->postJson("{$base}/plans", [
            'name' => 'Amend services', 'provider_id' => $provider, 'family' => 'tenant',
        ])->assertCreated()->json('id');
        $version = $this->version($plan, $provider, 12000, 30);

        // Establish an Active term spanning the test window.
        $subscription = $this->postJson("{$base}/subscriptions", [
            'account_id' => $account, 'plan_version_id' => $version, 'starts_on' => '2032-03-04',
            'renewal_mode' => 'manual', 'coverage_mode' => 'organization',
        ])->assertCreated()->json('id');
        $invoiceResponse = $this->postJson("{$base}/invoices", ['subscription_id' => $subscription, 'due_on' => '2032-03-22'])
            ->assertCreated();
        $invoice = $invoiceResponse->json('id');
        $this->postJson("{$base}/invoices/{$invoice}/payments", [
            'amount_paise' => $invoiceResponse->json('amount_paise'), 'receipt_reference' => 'AMEND-1',
        ])->assertCreated();
        $this->postJson("{$base}/subscriptions/{$subscription}/transition", [
            'action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'Term paid',
        ])->assertOk();

        return [$base, [
            'portal' => $portal, 'provider' => $provider, 'organization' => $organization,
            'plan' => $plan, 'version' => $version, 'account' => $account, 'subscription' => $subscription, 'tenant' => $tenant,
        ]];
    }

    private function plan(string $provider): string
    {
        return $this->postJson('/api/v1/commercial/plans', [
            'name' => 'Alternate services', 'provider_id' => $provider, 'family' => 'tenant',
        ])->assertCreated()->json('id');
    }

    private function version(string $plan, string $provider, int $amount, int $termDays): string
    {
        return $this->postJson("/api/v1/commercial/plans/{$plan}/versions", [
            'amount_paise' => $amount, 'term_days' => $termDays, 'entitlements' => ['portal.reports'],
        ])->assertCreated()->json('id');
    }

    private function business(string $kind, ?string $parent = null): string
    {
        $identity = Str::uuid7()->toString();
        DB::table('business_identities')->insert([
            'id' => $identity, 'kind' => $kind, 'parent_id' => $parent,
            'professional_name' => $kind, 'operational_state' => 'Active',
        ]);

        return $identity;
    }
}
