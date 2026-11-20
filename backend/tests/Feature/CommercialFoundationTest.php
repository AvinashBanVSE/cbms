<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $this->postJson("{$base}/subscriptions/{$tenant}/renew", [])->assertForbidden();
        $this->postJson($base.'/add_ons', [])->assertForbidden();
        foreach (['commercial.manage', 'business_structure.create', 'global_business_settings.manage'] as $permission) {
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
        $nextVersion = $this->postJson($publish, [...$terms, 'amount_paise' => 20000, 'entitlements' => []])
            ->assertCreated()->assertJsonPath('version', 2)->json('id');
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
        $this->assertSubscriptionLifecycle($base, $selected, $child, $organization, $otherChild, $nextVersion);
        $this->assertAutomaticRenewal($base, [...$input, 'coverage_mode' => 'future_network'], $organization, $child);
        DB::table('customer_tenants')->where('id', $tenant)->update(['provider_id' => $portal]);
        $this->postJson($base.'/subscriptions', [...$input, 'coverage_mode' => 'organization'])->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2032-03-02', 'Asia/Kolkata'));
        $this->postJson("{$base}/subscriptions/{$self}/transition", [
            'action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'Enable services',
        ])->assertConflict()->assertJsonPath('message', 'This agreement requires a separate commercial transition.');
        $this->assertDatabaseHas('commercial_accounts', ['id' => $account, 'provider_id' => $provider]);
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $selected, 'plan_version_id' => $version]);
    }

    private function assertAutomaticRenewal(string $base, array $input, string $organization, string $child): void
    {
        $this->travelTo(CarbonImmutable::parse('2032-03-20', 'Asia/Kolkata'));
        $terms = [...$input, 'renewal_mode' => 'automatic'];
        $this->postJson($base.'/subscriptions', $terms)->assertUnprocessable();
        $this->postJson($base.'/subscriptions', [...$terms, 'renewal_lead_days' => 31])->assertUnprocessable();
        $this->postJson($base.'/subscriptions', [...$terms, 'renewal_lead_days' => 5, 'renewal_grace_days' => 3])->assertUnprocessable();
        $subscription = $this->postJson($base.'/subscriptions', [
            ...$terms, 'renewal_lead_days' => 5, 'renewal_grace_days' => 3, 'renewal_grace_policy' => 'retain_features',
        ])->assertCreated()->json('id');
        $addOn = $this->attachFeatureAddOn($base, $subscription, $input, $child);
        $invoice = $this->postJson($base.'/invoices', ['subscription_id' => $subscription, 'due_on' => '2032-03-20'])
            ->assertCreated()->assertJsonPath('amount_paise', 12445)->json('id');
        $this->postJson("{$base}/subscriptions/{$subscription}/add_ons", ['add_on_version_id' => $addOn, 'business_id' => $organization])
            ->assertConflict();
        $this->postJson("{$base}/invoices/{$invoice}/payments", ['amount_paise' => 12445, 'receipt_reference' => 'AUTO-FIRST'])->assertCreated();
        $this->postJson("{$base}/subscriptions/{$subscription}/transition", [
            'action' => 'activate', 'expected_state' => 'Pending Activation', 'reason' => 'First term paid',
        ])->assertOk();
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseMissing('commercial_subscriptions', ['renewed_from_id' => $subscription]);

        $this->travelTo(CarbonImmutable::parse('2032-03-24', 'Asia/Kolkata'));
        config(['queue.default' => 'database']);
        $account = DB::table('commercial_accounts')->where('id', $input['account_id'])->first();
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseMissing('commercial_subscriptions', ['renewed_from_id' => $subscription]);
        $otherProvider = DB::table('portal_installation')->value('portal_id');
        DB::table('customer_tenants')->where('id', $account->tenant_id)->update(['provider_id' => $otherProvider]);
        Queue::connection('database')->pop()->fire();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseMissing('commercial_subscriptions', ['renewed_from_id' => $subscription]);
        DB::table('customer_tenants')->where('id', $account->tenant_id)->update(['provider_id' => $account->provider_id]);
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        Queue::connection('database')->pop()->fire();
        $this->assertDatabaseCount('jobs', 0);
        config(['queue.default' => 'sync']);
        $next = DB::table('commercial_subscriptions')->where('renewed_from_id', $subscription)->first();
        $this->assertSame('2032-03-29', $next->starts_on);
        $this->assertSame('automatic', $next->renewal_mode);
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $next->id, 'business_id' => $child]);
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $next->id, 'business_id' => $organization]);
        $this->assertDatabaseHas('commercial_subscription_add_ons', ['subscription_id' => $next->id, 'business_id' => $child, 'add_on_version_id' => $addOn]);
        $this->assertDatabaseHas('commercial_invoices', [
            'subscription_id' => $next->id, 'issued_on' => '2032-03-24', 'due_on' => '2032-03-29', 'amount_paise' => 12445,
        ]);
        $this->postJson("{$base}/subscriptions/{$subscription}/transition", [
            'action' => 'cancel', 'expected_state' => 'Active', 'reason' => 'Agreement ended by request',
        ])->assertConflict()->assertJsonPath('message', 'Cancel the upcoming renewal before cancelling this term.');
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertSame(1, DB::table('commercial_subscriptions')->where('renewed_from_id', $subscription)->count());

        $this->travelTo(CarbonImmutable::parse('2032-03-29', 'Asia/Kolkata'));
        $provider = DB::table('commercial_accounts')->where('id', $input['account_id'])->value('provider_id');
        $otherTenant = Str::uuid7()->toString();
        $otherOrganization = $this->business('Organization');
        DB::table('customer_tenants')->insert(['id' => $otherTenant, 'organization_id' => $otherOrganization, 'provider_id' => $provider]);
        $foreignAccount = $this->postJson($base.'/accounts', [
            'tenant_id' => $otherTenant, 'provider_id' => $provider, 'billing_party' => 'Other Organization',
        ])->assertCreated()->json('id');
        DB::table('commercial_subscriptions')->where('id', $next->id)->update(['account_id' => $foreignAccount]);
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $next->id, 'state' => 'Pending Activation']);
        $this->assertDatabaseMissing('portal_events', ['action' => 'commercial.subscription_state_changed', 'subject_id' => $next->id]);
        DB::table('commercial_subscriptions')->where('id', $next->id)->update(['account_id' => $input['account_id']]);
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('commercial_subscriptions', [
            'id' => $next->id, 'state' => 'Grace Period', 'grace_ends_before' => '2032-04-01',
        ]);
        $available = "{$base}/businesses/{$child}/effective-entitlements";
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', ['portal.exports', 'portal.reports']);
        $this->getJson("{$base}/businesses/{$organization}/effective-entitlements")->assertOk()->assertJsonPath('feature_codes', ['portal.reports']);
        $this->travelTo(CarbonImmutable::parse('2032-04-01', 'Asia/Kolkata'));
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', []);
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonFragment(['effective_state' => 'Suspended']);
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $next->id, 'state' => 'Suspended']);
        $this->assertDatabaseHas('business_identities', ['id' => $child, 'operational_state' => 'Active']);
        $invoice = DB::table('commercial_invoices')->where('subscription_id', $next->id)->value('id');
        $this->postJson("{$base}/invoices/{$invoice}/payments", ['amount_paise' => 12445, 'receipt_reference' => 'AUTO-NEXT'])->assertCreated();
        $this->artisan('commercial:renew-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $next->id, 'state' => 'Suspended']);
        $this->postJson("{$base}/subscriptions/{$next->id}/transition", [
            'action' => 'activate', 'expected_state' => 'Suspended', 'reason' => 'Overdue invoice settled',
        ])->assertOk();
        $this->assertDatabaseHas('commercial_subscriptions', ['id' => $next->id, 'state' => 'Active']);
        $this->assertDatabaseHas('portal_events', ['action' => 'commercial.subscription_state_changed', 'actor_id' => null, 'subject_id' => $next->id]);
        $this->travelBack();
    }

    private function attachFeatureAddOn(string $base, string $subscription, array $input, string $child): string
    {
        $provider = DB::table('commercial_accounts')->where('id', $input['account_id'])->value('provider_id');
        $other = $this->business('Organization');
        $this->postJson($base.'/entitlements', ['code' => 'portal.exports', 'name' => 'Exports'])->assertCreated();
        $offer = ['provider_id' => $provider, 'family' => 'tenant', 'name' => 'Exports'];
        $addOn = $this->postJson($base.'/add_ons', $offer)->assertCreated()->json('id');
        $this->postJson($base.'/add_ons', $offer)->assertConflict();
        $publish = "{$base}/add_ons/{$addOn}/versions";
        $release = ['plan_version_id' => $input['plan_version_id'], 'amount_paise' => 100, 'entitlements' => ['portal.exports']];
        $this->postJson($publish, [...$release, 'plan_version_id' => Str::uuid7()->toString()])->assertUnprocessable();
        $version = $this->postJson($publish, $release)->assertCreated()->assertJsonPath('version', 1)->json('id');
        $later = $this->postJson($publish, [...$release, 'amount_paise' => 200])->assertCreated()->assertJsonPath('version', 2)->json('id');
        $plan = DB::table('commercial_plan_versions')->where('id', $input['plan_version_id'])->value('plan_id');
        $nextPlan = DB::table('commercial_plan_versions')->where('plan_id', $plan)->where('version', 2)->value('id');
        $incompatible = $this->postJson($publish, [...$release, 'plan_version_id' => $nextPlan])->assertCreated()->json('id');
        $this->getJson($base.'?section=add_on_versions')->assertOk()->assertJsonCount(3, 'data');

        $attach = "{$base}/subscriptions/{$subscription}/add_ons";
        $assignment = ['add_on_version_id' => $version, 'business_id' => $child];
        $this->postJson($attach, [...$assignment, 'business_id' => $other])->assertUnprocessable();
        $this->postJson($attach, [...$assignment, 'add_on_version_id' => $incompatible])->assertUnprocessable();
        $this->postJson($attach, $assignment)->assertCreated();
        $this->postJson($attach, $assignment)->assertOk();
        $this->postJson($attach, [...$assignment, 'add_on_version_id' => $later])->assertConflict();
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonFragment(['add_on_version_id' => $version]);

        return $version;
    }

    private function assertSubscriptionLifecycle(string $base, string $subscription, string $child, string $organization, string $otherChild, string $nextVersion): void
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

        $renew = "{$base}/subscriptions/{$subscription}/renew";
        $renewal = ['plan_version_id' => $nextVersion, 'reason' => 'Next cycle Plan Version approved'];
        $this->postJson($renew, [...$renewal, 'plan_version_id' => Str::uuid7()->toString()])->assertUnprocessable();
        $nextTerm = $this->postJson($renew, $renewal)->assertCreated()->assertJsonPath('starts_on', '2032-03-29')->json('id');
        $this->postJson($renew, $renewal)->assertOk()->assertJsonPath('id', $nextTerm);
        $this->postJson($renew, [...$renewal, 'plan_version_id' => Str::uuid7()->toString()])->assertConflict();
        $this->getJson($base.'?section=subscriptions')->assertOk()->assertJsonFragment(['renewal_id' => $nextTerm]);
        $this->assertDatabaseHas('commercial_subscriptions', [
            'id' => $nextTerm, 'renewed_from_id' => $subscription, 'plan_version_id' => $nextVersion,
            'state' => 'Pending Activation', 'ends_before' => '2032-04-28',
        ]);
        $this->assertDatabaseHas('commercial_subscription_coverage', ['subscription_id' => $nextTerm, 'business_id' => $child]);
        $this->assertDatabaseMissing('commercial_subscription_coverage', ['subscription_id' => $nextTerm, 'business_id' => $otherChild]);
        $nextTransition = "{$base}/subscriptions/{$nextTerm}/transition";
        $nextInvoice = $this->postJson($base.'/invoices', ['subscription_id' => $nextTerm, 'due_on' => '2032-03-29'])
            ->assertCreated()->assertJsonPath('amount_paise', 20000)->json('id');
        $this->postJson("{$base}/invoices/{$nextInvoice}/payments", ['amount_paise' => 20000, 'receipt_reference' => 'NEXT-TERM'])
            ->assertCreated();
        $this->postJson($nextTransition, $activate)->assertConflict()->assertJsonPath('message', 'The Subscription term has yet to start.');
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', ['portal.reports']);

        $cancel = ['action' => 'cancel', 'expected_state' => 'Active', 'reason' => 'Agreement ended by request'];
        $this->travelTo(CarbonImmutable::parse('2032-03-29', 'Asia/Kolkata'));
        $this->getJson($available)->assertOk()->assertJsonPath('feature_codes', []);
        $this->postJson($nextTransition, $activate)->assertOk()->assertJsonPath('state', 'Active');
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
        $this->postJson('/api/v1/global-business-settings/number-presentation/negative_amount/'.$organization, [
            'negative_amount' => 'parentheses', 'override_allowed' => true, 'locked' => false, 'expected_version' => 0,
        ])->assertOk();
        $this->getJson($payments)->assertOk()->assertJsonCount(3, 'history.data')
            ->assertJsonPath('presentation.negative_amount', 'parentheses')
            ->assertJsonFragment(['entry_type' => 'reversal', 'delta_paise' => -2000]);
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
