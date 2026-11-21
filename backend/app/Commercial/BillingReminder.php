<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Governed billing reminder policy (§14.7).
 *
 * A subscription that opts into reminders records a reminder for each outstanding invoice as its
 * due date approaches, using the reminder lead time configured on the subscription. A reminder is a
 * financial record — like a payment — not a notification: nothing is sent here. Recording is
 * idempotent per invoice, lead time and business day, state-gated to open invoices, and dated in
 * the Asia/Kolkata business calendar.
 */
final class BillingReminder
{
    /**
     * Record the applicable reminder for a subscription's invoice. Repeats are idempotent.
     *
     * @return array{reminder_id: string|null, invoice_id: string, outstanding_paise: int, days_before: int|null, duplicate: bool}
     */
    public function record(string $invoice, string $actor): array
    {
        return DB::transaction(function () use ($invoice, $actor): array {
            $row = DB::table('commercial_invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null, 404);

            $subscription = DB::table('commercial_subscriptions')->where('id', $row->subscription_id)
                ->lockForUpdate()->first();
            abort_unless($subscription->billing_reminder_policy === 'enabled', 422,
                'The subscription does not have a billing reminder policy enabled.');
            abort_if($subscription->billing_reminder_days === null, 422,
                'The billing reminder lead time is not configured.');

            $paid = (int) DB::table('commercial_billing_ledger')->where('invoice_id', $row->id)->sum('delta_paise');
            $outstanding = (int) $row->amount_paise - $paid;
            abort_if($outstanding <= 0, 422, 'The invoice is already settled.');

            $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            $dueOn = CarbonImmutable::parse($row->due_on, GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            $daysBefore = (int) $today->diffInDays($dueOn);
            abort_if($daysBefore > (int) $subscription->billing_reminder_days, 422,
                'The reminder lead time has not been reached.');

            $scheduledOn = $today->toDateString();
            $existing = DB::table('commercial_billing_reminders')->where('invoice_id', $row->id)
                ->where('scheduled_on', $scheduledOn)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->days_before === $daysBefore, 409,
                    'A different reminder lead time was already recorded for this invoice today.');

                return ['reminder_id' => $existing->id, 'invoice_id' => $row->id,
                    'outstanding_paise' => $outstanding, 'days_before' => $daysBefore, 'duplicate' => true];
            }

            $id = Str::uuid7()->toString();
            DB::table('commercial_billing_reminders')->insert([
                'id' => $id, 'invoice_id' => $row->id, 'days_before' => $daysBefore,
                'scheduled_on' => $scheduledOn, 'outstanding_paise' => $outstanding,
                'actor_id' => $actor, 'recorded_at' => now(),
            ]);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'commercial.billing_reminder_sent',
                'subject_id' => $row->id, 'actor_id' => $actor, 'recorded_at' => now(),
            ]);

            return ['reminder_id' => $id, 'invoice_id' => $row->id,
                'outstanding_paise' => $outstanding, 'days_before' => $daysBefore, 'duplicate' => false];
        }, attempts: 3);
    }

    /** Reminder history for an invoice, oldest first (recent last, like payment history). */
    public function history(string $invoice): array
    {
        return DB::table('commercial_billing_reminders')->where('invoice_id', $invoice)
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['id', 'invoice_id', 'days_before', 'scheduled_on', 'outstanding_paise', 'recorded_at'])
            ->toArray();
    }
}
