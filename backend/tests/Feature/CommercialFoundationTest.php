<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercialFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_plans_and_explicit_coverage_preserve_business_boundaries(): void
    {
        $portal = $this->business('Main Portal');
        $provider = $this->business('Sub-Portal');
        $organization = $this->business('Organization');
        $otherOrganization = $this->business('Organization');
        $child = $this->business('Shop', $organization);
        $otherChild = $this->business('Shop', $otherOrganization);
        $tenant = Str::uuid7()->toString();
        $administrator = Str::uuid7()->toString();
        $owner = Str::uuid7()->toString();
        DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $portal, 'stage' => 'ready']);
        DB::table('customer_tenants')->insert(['id' => $tenant, 'organization_id' => $organization, 'provider_id' => $provider]);
        DB::table('users')->insert(['id' => $administrator, 'code' => 'AA3911', 'email' => 'commercial@example.test', 'password' => 'unused']);
        DB::table('ownership_holders')->insert(['id' => $owner, 'name' => 'Owner']);
        DB::table('ownership_links')->insert(['id' => Str::uuid7()->toString(), 'business_id' => $organization, 'owner_id' => $owner]);
        $base = '/api/v1/commercial';
        $this->getJson($base.'?section=accounts')->assertUnauthorized();
        $this->actingAs(User::findOrFail($administrator));
        $this->getJson($base.'?section=accounts')->assertForbidden();
        $this->getJson("{$base}/businesses/{$child}/effective-entitlements")->assertForbidden();
        $this->postJson("{$base}/subscriptions/{$tenant}/transition", [])->assertForbidden();
        foreach (['commercial.manage', 'business_structure.create'] as $permission) {
            DB::table('portal_grants')->insert(['business_id' => $portal, 'user_id' => $administrator, 'permission' => $permission]);
        }

        $contract = ['tenant_id' => $tenant, 'provider_id' => $provider, 'billing_party' => 'Contracting entity'];
        $this->postJson($base.'/accounts', [...$contract, 'provider_id' => $portal])->assertUnprocessable();
        $account = $this->postJson($base.'/accounts', $contract)->assertCreated()->json('id');
        $feature = ['code' => 'portal.reports', 'name' => 'Reports'];
        $this->postJson($base.'/entitlements', $feature)->assertCreated();
        $this->postJson($base.'/entitlements', $feature)->assertConflict();
        $product = ['name' => 'Organization services', 'provider_id' => $provider, 'family' => 'tenant'];
        $plan = $this->postJson($base.'/plans', $product)->assertCreated()->json('id');
        $this->postJson($base.'/plans', $product)->assertConflict();

        $publish = "{$base}/plans/{$plan}/versions";
        $terms = ['amount_paise' => 12345, 'term_days' => 30, 'entitlements' => ['portal.reports']];
        $this->postJson($publish, [...$terms, 'entitlements' => ['unknown.feature']])->assertUnprocessable();
        $version = $this->postJson($publish, $terms)->assertCreated()->assertJsonPath('version', 1)->json('id');
        $this->postJson($publish, [...$terms, 'amount_paise' => 20000])->assertCreated()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('commercial_plan_versions', ['id' => $version, 'amount_paise' => 12345]);
        $this->getJson($base.'?section=versions')->assertOk()->assertJsonFragment(['composition' => 'any']);

        $input = ['account_id' => $account, 'plan_version_id' => $version, 'starts_on' => '2032-02-28', 'renewal_mode' => 'manual'];
        $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'selected', 'business_ids' => [$otherChild]])->assertUnprocessable();
        $selected = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'selected', 'business_ids' => [$child]])->assertCreated()->json('id');
        $self = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'organization'])->assertCreated()->json('id');
        $network = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'network'])->assertCreated()->json('id');
        $future = $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'future_network'])->assertCreated()->json('id');
        $futureChild = $this->postJson('/api/v1/businesses', ['kind' => 'Shop', 'parent_id' => $organization, 'professional_name' => 'Future Shop'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $future, 'business_id' => $futureChild]);
        $this->assertDatabaseMissing('commercial_subscription_coverage', ['subscription_id' => $network, 'business_id' => $futureChild]);
        $this->assertDatabaseMissing('commercial_subscription_coverage', ['subscription_id' => $selected, 'business_id' => $organization]);
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $self, 'business_id' => $organization]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $future, 'state' => 'Pending Activation', 'ends_before' => '2032-03-29']);
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonCount(4, 'data');
        $this->assertDatabaseCount('customer_permissions', 0);
        $this->travelTo(CarbonImmutable::parse('2032-02-28', 'Asia/Kolkata'));
        $this->postJson("{$base}/subscriptions/{$self}/transition", [
            'action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'Enable services',
        ])->assertConflict()->assertJsonPath('message', 'Issue the term invoice before activation.');
        $this->travelBack();

        $this->assertBilling($base, $selected, $organization);
        $this->assertSubscriptionLifecycle($base, $selected, $child, $organization, $otherChild);
        DB::table('customer_tenants')->where('id', $tenant)->update(['provider_id' => $portal]);
        $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'organization'])->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2032-03-02', 'Asia/Kolkata'));
        $this->postJson("{$base}/subscriptions/{$self}/transition", [
            'action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'Enable services',
        ])->assertConflict()->assertJsonPath('message', 'This agreement requires a separate commercial transition.');
        $this->assertDatabaseHas('commercial_accounts', ['id' => $account, 'provider_id' => $provider]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $selected, 'plan_version_id' => $version]);
    }

    private function assertSubscriptionLifecycle(string $base, string $subscription, string $child, string $organization, string $otherChild): void
    {
        $transition = "{$base}/subscriptions/{$subscription}/transition";
        $available = "{$base}/businesses/{$child}/effective-entitlements";
        $activate = ['action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'Full term payment verified'];
        $this->travelTo(CarbonImmutable::parse('2032-02-27 18:29:59', 'UTC'));
        $this->postJson($transition, $activate)->assertConflict()->assertJsonPath('message', 'The Subscription term has yet to start.');
        $this->travel(1)->seconds();
        $this->postJson($transition, $activate)->assertConflict()->assertJsonPath('message', 'Settle the term invoice before activation.');
        $this->postJson($transition, [...$activate, 'action' => 'suspend'])->assertConflict();
        $this->getJson($available)->assertOk()->assertExactJson(['business_id' => $child, 'feature_codes' => []]);

        $invoice = DB::table('commercial_invoices')->where('subscription_id', $subscription)->value('id');
        $this->travelTo(CarbonImmutable::parse('2032-03-03', 'Asia/Kolkata'));
        $payments = "{$base}/invoices/{$invoice}/payments";
        $payment = $this->postJson($payments, ['receipt_reference' => 'REPLACEMENT', 'amount_paise' => 2000])->assertCreated()->json('payment_id');
        $this->postJson($transition, $activate)->assertOk()->assertJsonPath('state', 'Active');
        $this->postJson($transition, $activate)->assertConflict()->assertJsonPath('message', 'The Subscription state changed. Reload the commercial records.');
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', ['portal.reports']);
        $this->getJson("{$base}/businesses/{$organization}/effective-entitlements")->assertOk()->assertJsonPath('feature_codes', []);
        DB::table('commercial_subscription_coverage')->insert(['subscription_id' => $subscription, 'business_id' => $otherChild]);
        $this->getJson("{$base}/businesses/{$otherChild}/effective-entitlements")->assertOk()->assertJsonPath('feature_codes', []);
        $unknown = Str::uuid7()->toString();
        $this->getJson("{$base}/businesses/{$unknown}/effective-entitlements")->assertNotFound();
        $this->postJson("{$base}/subscriptions/{$unknown}/transition", $activate)->assertNotFound();
        $this->postJson($transition, [...$activate, 'reason' => ''])->assertUnprocessable();

        $this->postJson("{$payments}/{$payment}/reverse", ['reason' => 'Replacement receipt reversed'])->assertOk();
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', ['portal.reports']);
        $suspend = ['action' => 'suspend', 'expected_state' => 'Active', 'reason' => 'Commercial review'];
        $this->postJson($transition, $suspend)->assertOk()->assertJsonPath('state', 'Suspended');
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', []);
        $reinstate = [...$activate, 'expected_state' => 'Suspended'];
        $this->postJson($transition, $reinstate)->assertConflict()->assertJsonPath('message', 'Settle the term invoice before activation.');
        $this->postJson($payments, ['receipt_reference' => 'FINAL', 'amount_paise' => 2000])->assertCreated();
        $this->postJson($transition, $reinstate)->assertOk()->assertJsonPath('state', 'Active');
        $this->assertDatabaseHas('portal_events', ['subject_id' => $subscription, 'action' => 'commercial.subscription_state_changed',
            'details' => '{"from":"Active","to":"Suspended","reason":"Commercial review"}']);

        $cancel = ['action' => 'cancel', 'expected_state' => 'Active', 'reason' => 'Agreement ended by request'];
        $this->travelTo(CarbonImmutable::parse('2032-03-29', 'Asia/Kolkata'));
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', []);
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonFragment(['effective_state' => 'Expired']);
        $this->postJson($transition, $cancel)->assertConflict()->assertJsonPath('message', 'The Subscription term has ended.');
        $this->travelTo(CarbonImmutable::parse('2032-03-28', 'Asia/Kolkata'));
        $this->postJson($transition, $cancel)->assertOk()->assertJsonPath('state', 'Cancelled');
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', []);
        $this->assertDatabaseHas('business_identities', ['id' => $child, 'operational_state' => 'Active']);
        $this->assertDatabaseCount('customer_permissions', 0);
        $this->travelBack();
    }

    private function assertBilling(string $base, string $subscription, string $organization): void
    {
        $this->travelTo(CarbonImmutable::parse('2032-02-28 21:00:00', 'UTC'));
        $terms = ['subscription_id' => $subscription, 'due_on' => '2032-02-29'];
        $this->postJson($base.'/invoices', [...$terms, 'due_on' => '2032-02-28'])->assertUnprocessable();
        $bill = $this->postJson($base.'/invoices', $terms)->assertCreated()
            ->assertJsonPath('issued_on', '2032-02-29')->assertJsonPath('amount_paise', 12345)
            ->assertJsonPath('status', 'Issued/Open')->json('id');
        $this->postJson($base.'/invoices', $terms)->assertOk()->assertJsonPath('id', $bill);
        $payments = "{$base}/invoices/{$bill}/payments";
        $first = ['receipt_reference' => 'TEST-RECEIPT-1', 'amount_paise' => 2000];
        $payment = $this->postJson($payments, $first)->assertCreated()
            ->assertJsonPath('invoice.status', 'Partially Paid')->assertJsonPath('invoice.outstanding_paise', 10345)->json('payment_id');
        $this->postJson($payments, $first)->assertOk()->assertJsonPath('payment_id', $payment);
        $this->postJson($payments, [...$first, 'amount_paise' => 3000])->assertConflict();
        $this->postJson($payments, ['receipt_reference' => 'TEST-OVERPAY', 'amount_paise' => 10346])->assertUnprocessable();
        $this->postJson($payments, ['receipt_reference' => 'TEST-RECEIPT-2', 'amount_paise' => 10345])->assertCreated()
            ->assertJsonPath('invoice.status', 'Paid');
        $this->getJson($base.'/invoices')->assertOk()->assertJsonPath('data.0.status', 'Paid');

        $this->travel(1)->days();
        $reversal = ['reason' => 'Receipt corrected'];
        $this->postJson("{$payments}/{$payment}/reverse", $reversal)->assertOk()->assertJsonPath('status', 'Past Due')
            ->assertJsonPath('outstanding_paise', 2000);
        $this->postJson("{$payments}/{$payment}/reverse", $reversal)->assertOk();
        $this->postJson("{$payments}/{$payment}/reverse", ['reason' => 'Different reason'])->assertConflict();
        $this->postJson($base.'/invoices', $terms)->assertOk()->assertJsonPath('id', $bill);
        $this->getJson($payments)->assertOk()->assertJsonCount(3, 'history.data');
        $this->assertDatabaseHas('commercial_payments', ['id' => $payment, 'amount_paise' => 2000]);
        $this->assertDatabaseHas('commercial_billing_ledger', ['payment_id' => $payment, 'entry_type' => 'reversal', 'delta_paise' => -2000]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $subscription, 'state' => 'Pending Activation']);
        $this->assertDatabaseHas('business_identities', ['id' => $organization, 'operational_state' => 'Active']);
        $this->travelBack();
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
