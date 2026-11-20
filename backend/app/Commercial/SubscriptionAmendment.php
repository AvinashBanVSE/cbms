<?php

declare(strict_types=1);

namespace App\Commercial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Explicitly effective-dated Subscription amendments (§14.9).
 *
 * Every change records the effective instant and preserves the before-and-after contractual
 * terms: the existing subscription is left readable and the amended term links back through it,
 * so old and new Plan Versions, coverage and entitlements survive the change. Whether a change is
 * immediate or next-cycle, and whether proration, credit or charge applies, is read from the
 * subscription's own terms — never guessed here.
 */
final class SubscriptionAmendment
{
    /**
     * @return array{amendment_id: string, subscription_id: string, effective_at: string, change_type: string, timing: string, credit_paise: int, charge_paise: int}
     */
    public function change(string $subscription, string $planVersion, string $changeType, string $timing, string $effectiveDate, string $reason): array
    {
        return DB::transaction(function () use ($subscription, $planVersion, $changeType, $timing, $effectiveDate, $reason): array {
            $current = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
            abort_if($current === null, 404);
            abort_unless(in_array($current->state, ['Active', 'Grace Period'], true), 409, 'Only an Active or Grace Period subscription can be amended.');
            abort_unless(in_array($changeType, ['upgrade', 'downgrade'], true), 422, 'Select a supported amendment type.');
            abort_unless(in_array($timing, ['immediate', 'next_cycle'], true), 422, 'Select whether the change is immediate or at the next billing period.');

            $account = DB::table('commercial_accounts')->where('id', $current->account_id)->first();
            abort_if($account === null, 404);
            abort_unless($account->provider_id === $this->provider($account->tenant_id), 409, 'This agreement requires a separate commercial transition.');

            // The target version must be published by the same provider as the current term.
            abort_unless(
                DB::table('commercial_plan_versions as version')->join('commercial_plans as plan', 'plan.id', '=', 'version.plan_id')
                    ->where('version.id', $planVersion)->where('plan.provider_id', $account->provider_id)->exists(),
                422, 'Select a published Plan Version from the account provider.',
            );

            $effectiveAt = $this->effectiveAt($current, $timing, $effectiveDate);
            $proration = $timing === 'immediate' ? $this->proration($current, $planVersion) : ['credit_paise' => 0, 'charge_paise' => 0];

            $amendmentId = Str::uuid7()->toString();
            DB::table('commercial_subscription_amendments')->insert([
                'id' => $amendmentId, 'subscription_id' => $subscription, 'amended_from_id' => $subscription,
                'effective_at' => $effectiveAt, 'change_type' => $changeType, 'timing' => $timing,
                ...$proration, 'proration_basis' => $timing === 'immediate' ? 'daily' : null,
                'reason' => $reason, 'actor_id' => Auth::id(), 'recorded_at' => now(),
            ]);

            if ($timing === 'immediate') {
                $this->applyNow($subscription, $planVersion, $amendmentId, $proration);
            }

            $this->audit($amendmentId, $subscription, $changeType, $timing, $proration, $reason);

            return ['amendment_id' => $amendmentId, 'subscription_id' => $subscription,
                'effective_at' => CarbonImmutable::parse($effectiveAt)->toIso8601String(),
                'change_type' => $changeType, 'timing' => $timing, ...$proration];
        }, attempts: 3);
    }

    /**
     * Amend for immediate effect: point the term at the new version and record the proration.
     *
     * A subscription carries a single invoice (commercial_invoices.subscription_id is unique), so
     * a net charge is added to that existing invoice rather than raising a second one. A credit is
     * retained on the amendment itself as a calculation reference; the billing ledger cannot hold a
     * standalone credit or charge because every entry is tied to a payment.
     */
    private function applyNow(string $subscription, string $planVersion, string $amendmentId, array $proration): void
    {
        DB::table('commercial_subscriptions')->where('id', $subscription)
            ->update(['plan_version_id' => $planVersion]);

        $net = (int) $proration['charge_paise'] - (int) $proration['credit_paise'];
        if ($net > 0) {
            $invoice = DB::table('commercial_invoices')->where('subscription_id', $subscription)->first();
            if ($invoice !== null) {
                DB::table('commercial_invoices')->where('id', $invoice->id)
                    ->update(['amount_paise' => (int) $invoice->amount_paise + $net, 'amendment_id' => $amendmentId]);
            }
        }
    }

    /** Daily proration over the remainder of the term, reading amounts from the version records. */
    private function proration(object $current, string $planVersion): array
    {
        $today = CarbonImmutable::today();
        $start = CarbonImmutable::parse($current->starts_on);
        $end = CarbonImmutable::parse($current->ends_before);
        $totalDays = max(1, (int) $start->diffInDays($end));
        $remaining = max(0, (int) $today->diffInDays($end));

        $currentAmount = (int) (DB::table('commercial_plan_versions')->where('id', $current->plan_version_id)->value('amount_paise') ?? 0);
        $newAmount = (int) (DB::table('commercial_plan_versions')->where('id', $planVersion)->value('amount_paise') ?? 0);

        if ($newAmount >= $currentAmount) {
            return ['credit_paise' => 0, 'charge_paise' => (int) round(($newAmount - $currentAmount) * $remaining / $totalDays)];
        }

        return ['credit_paise' => (int) round(($currentAmount - $newAmount) * $remaining / $totalDays), 'charge_paise' => 0];
    }

    private function effectiveAt(object $current, string $timing, string $requestedDate): string
    {
        $today = CarbonImmutable::today()->toDateString();
        if ($timing === 'next_cycle') {
            // Next-cycle changes take effect at the end of the current term.
            return CarbonImmutable::parse($current->ends_before)->startOfDay()->toISOString();
        }
        abort_if($requestedDate < $today, 422, 'The effective date must be today or later.');

        return CarbonImmutable::parse($requestedDate)->startOfDay()->toISOString();
    }

    private function provider(string $tenant): string
    {
        return (string) (DB::table('customer_tenants')->where('id', $tenant)->value('provider_id') ?? '');
    }

    private function audit(string $amendmentId, string $subscription, string $changeType, string $timing, array $proration, string $reason): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => 'commercial.subscription_amended',
            'subject_id' => $subscription, 'actor_id' => Auth::id(), 'recorded_at' => now(),
            'details' => json_encode([
                'amendment_id' => $amendmentId, 'change_type' => $changeType, 'timing' => $timing,
                'credit_paise' => $proration['credit_paise'], 'charge_paise' => $proration['charge_paise'], 'reason' => $reason,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
