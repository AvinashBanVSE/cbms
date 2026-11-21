<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Governed payment retry (dunning) policy (§14.8).
 *
 * A subscription whose policy defines retries records each payment attempt for a
 * past-due invoice up to the configured maximum, spaced by the configured interval in
 * days. An attempt is a governed financial record — not an automatic debit: there is no
 * payment transport in this system, so a retry is scheduled and settled by recording a
 * payment. Scheduling is idempotent per invoice, attempt and business day, state-gated to
 * open invoices, and dated in the Asia/Kolkata business calendar.
 */
final class PaymentRetry
{
    /**
     * Schedule the next payment retry for a subscription's invoice. Repeats are idempotent.
     *
     * @return array{retry_id: string|null, invoice_id: string, attempt: int, scheduled_on: string, outstanding_paise: int, duplicate: bool}
     */
    public function schedule(string $invoice, string $actor): array
    {
        return DB::transaction(function () use ($invoice, $actor): array {
            $row = DB::table('commercial_invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null, 404);

            $subscription = DB::table('commercial_subscriptions')->where('id', $row->subscription_id)
                ->lockForUpdate()->first();
            abort_unless($subscription->payment_retry_policy === 'enabled', 422,
                'The subscription does not have a payment retry policy enabled.');
            abort_if($subscription->payment_retry_max_attempts === null, 422,
                'The payment retry maximum is not configured.');
            abort_if($subscription->payment_retry_interval_days === null, 422,
                'The payment retry interval is not configured.');

            $paid = (int) DB::table('commercial_billing_ledger')->where('invoice_id', $row->id)->sum('delta_paise');
            $outstanding = (int) $row->amount_paise - $paid;
            abort_if($outstanding <= 0, 422, 'The invoice is already settled.');
            abort_unless($row->due_on < CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE)
                ->toDateString(), 422, 'A payment retry is only available after the due date has passed.');

            $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
            $scheduled = DB::table('commercial_payment_retries')->where('invoice_id', $row->id)
                ->orderBy('attempt')->get();

            // Idempotency is checked before the interval gate so a same-day repeat of the next
            // attempt returns the existing record rather than failing the spacing rule.
            $existing = $scheduled->firstWhere(fn (object $attemptRow): bool => $attemptRow->scheduled_on === $today);
            if ($existing !== null) {
                abort_unless((int) $existing->attempt === $scheduled->count(), 409,
                    'A different retry attempt was already scheduled for this invoice today.');

                return ['retry_id' => $existing->id, 'invoice_id' => $row->id,
                    'attempt' => (int) $existing->attempt, 'scheduled_on' => $today,
                    'outstanding_paise' => $outstanding, 'duplicate' => true];
            }

            $attempt = $scheduled->count() + 1;
            abort_if($attempt > (int) $subscription->payment_retry_max_attempts, 422,
                'The payment retry maximum has already been reached.');
            if ($attempt > 1) {
                $last = $scheduled->last();
                $earliest = CarbonImmutable::parse($last->scheduled_on, GlobalBusinessSettings::BUSINESS_TIME_ZONE)
                    ->addDays((int) $subscription->payment_retry_interval_days);
                abort_if(CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->lt($earliest), 422,
                    'The payment retry interval has not elapsed since the previous attempt.');
            }

            $id = Str::uuid7()->toString();
            DB::table('commercial_payment_retries')->insert([
                'id' => $id, 'invoice_id' => $row->id, 'attempt' => $attempt, 'scheduled_on' => $today,
                'outstanding_paise' => $outstanding, 'state' => 'Scheduled', 'actor_id' => $actor,
                'recorded_at' => now(),
            ]);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'commercial.payment_retry_scheduled',
                'subject_id' => $row->id, 'actor_id' => $actor, 'recorded_at' => now(),
            ]);

            return ['retry_id' => $id, 'invoice_id' => $row->id, 'attempt' => $attempt,
                'scheduled_on' => $today, 'outstanding_paise' => $outstanding, 'duplicate' => false];
        }, attempts: 3);
    }

    /** Retry attempt history for an invoice, oldest first (recent last, like payment history). */
    public function history(string $invoice): array
    {
        return DB::table('commercial_payment_retries')->where('invoice_id', $invoice)
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['id', 'invoice_id', 'attempt', 'scheduled_on', 'outstanding_paise', 'state', 'recorded_at'])
            ->toArray();
    }
}
