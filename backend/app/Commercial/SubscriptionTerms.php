<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionTerms
{
    public static function duration(string $provider, string $version): int
    {
        $days = DB::table('commercial_plan_versions as version')->join('commercial_plans as plan', 'plan.id', '=', 'version.plan_id')
            ->where('version.id', $version)->where('plan.provider_id', $provider)
            ->where('plan.family', 'tenant')->value('version.term_days');
        abort_if($days === null, 422, 'Select a published Tenant Plan Version from the account provider.');

        return (int) $days;
    }

    public static function issueInvoice(object $subscription, string $issuedOn, string $dueOn, ?string $actor): object
    {
        $charge = SubscriptionAddOns::total($subscription);
        $row = [
            'id' => Str::uuid7()->toString(), 'subscription_id' => $subscription->id,
            'amount_paise' => (int) $charge, 'issued_on' => $issuedOn, 'due_on' => $dueOn,
        ];
        DB::table('commercial_invoices')->insert($row);
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => 'commercial.invoice_issued', 'actor_id' => $actor,
            'subject_id' => $row['id'], 'recorded_at' => now(), 'details' => json_encode([
                'subscription_id' => $subscription->id, 'amount_paise' => (int) $charge,
            ], JSON_THROW_ON_ERROR),
        ]);

        return (object) $row;
    }

    public static function invoiceForSettlement(string $subscription): ?object
    {
        return DB::table('commercial_invoices')->where('subscription_id', $subscription)->lockForUpdate()->first();
    }

    public static function isSettled(object $invoice): bool
    {
        $paid = (int) DB::table('commercial_billing_ledger')->where('invoice_id', $invoice->id)
            ->lockForUpdate()->pluck('delta_paise')->sum();

        return $paid === (int) $invoice->amount_paise;
    }
}
