<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Commercial\BillingReminder;
use App\Commercial\SubscriptionTerms;
use App\Configuration\BusinessTimePresentation;
use App\Configuration\GlobalBusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialBillingController
{
    public function __construct(private readonly MainPortalAccess $access) {}

    public function index(): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $invoices = DB::table('commercial_invoices')->orderBy('id')->simplePaginate(30);
        $totals = DB::table('commercial_billing_ledger')->whereIn('invoice_id', $invoices->getCollection()->pluck('id'))
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(delta_paise) AS paid')->pluck('paid', 'invoice_id');
        $invoices->through(fn (object $invoice): array => $this->balance($invoice, (int) ($totals[$invoice->id] ?? 0)));

        return response()->json($invoices)->header('Cache-Control', 'no-store');
    }

    public function invoice(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $today = now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
        $data = $request->validate([
            'subscription_id' => 'required|uuid',
            'due_on' => 'required|date_format:Y-m-d',
        ]);

        return DB::transaction(function () use ($data, $today): JsonResponse {
            $subscription = DB::table('commercial_subscriptions')->where('id', $data['subscription_id'])->lockForUpdate()->first();
            abort_if($subscription === null, 404);
            $prior = DB::table('commercial_invoices')->where('subscription_id', $subscription->id)->first();
            if ($prior !== null) {
                abort_unless($prior->due_on === $data['due_on'], 409);

                return response()->json($this->balance($prior, $this->paid($prior->id)));
            }
            abort_if($data['due_on'] < $today, 422, 'The payment due date must be today or later.');
            abort_unless(in_array($subscription->state, ['Pending Activation', 'Active'], true), 409);
            $invoice = SubscriptionTerms::issueInvoice($subscription, $today, $data['due_on'], Auth::id());

            return response()->json($this->balance($invoice, 0), 201);
        }, attempts: 3);
    }

    public function payments(string $invoice, BusinessTimePresentation $presentation): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $row = $this->findInvoice($invoice, false);
        $history = DB::table('commercial_billing_ledger as entry')
            ->join('commercial_payments as payment', 'payment.id', '=', 'entry.payment_id')
            ->where('entry.invoice_id', $invoice)->orderBy('entry.id')->simplePaginate(30, [
                'entry.id', 'entry.payment_id', 'entry.entry_type', 'entry.delta_paise', 'entry.reason',
                'entry.recorded_at', 'payment.receipt_reference',
            ]);
        $history->through(static function (object $entry): object {
            $entry->recorded_at = CarbonImmutable::parse($entry->recorded_at, 'UTC')->utc()->toISOString();

            return $entry;
        });

        $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');
        $organization = DB::table('commercial_subscriptions as subscription')
            ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->where('subscription.id', $row->subscription_id)->value('tenant.organization_id');
        $style = $presentation->resolve($portal, $organization, 'negative_amount')['effective']['negative_amount'];

        return response()->json([
            'invoice' => $this->balance($row, $this->paid($invoice)), 'history' => $history,
            'presentation' => ['negative_amount' => $style],
        ])->header('Cache-Control', 'no-store');
    }

    public function payment(Request $request, string $invoice): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'amount_paise' => 'required|integer|min:1|max:999999999999',
            'receipt_reference' => 'required|string|max:120',
        ]);
        $amount = (int) $data['amount_paise'];

        return DB::transaction(function () use ($invoice, $data, $amount): JsonResponse {
            $row = $this->findInvoice($invoice, true);
            $paid = $this->paid($invoice);
            $existing = DB::table('commercial_payments')->where('invoice_id', $invoice)
                ->where('receipt_reference', $data['receipt_reference'])->first();
            if ($existing) {
                abort_unless((int) $existing->amount_paise === $amount, 409);

                return response()->json(['payment_id' => $existing->id, 'invoice' => $this->balance($row, $paid)]);
            }
            abort_if($amount > (int) $row->amount_paise - $paid, 422, 'Payment exceeds the outstanding balance.');
            $id = Str::uuid7()->toString();
            DB::table('commercial_payments')->insert([
                'id' => $id, 'invoice_id' => $invoice, 'amount_paise' => $amount,
                'receipt_reference' => $data['receipt_reference'], 'recorded_at' => now(),
            ]);
            $this->entry($invoice, $id, 'payment', $amount);

            return response()->json(['payment_id' => $id, 'invoice' => $this->balance($row, $paid + $amount)], 201);
        }, attempts: 3);
    }

    public function reverse(Request $request, string $invoice, string $payment): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];

        return DB::transaction(function () use ($invoice, $payment, $reason): JsonResponse {
            $row = $this->findInvoice($invoice, true);
            $amount = DB::table('commercial_payments')->where(['invoice_id' => $invoice, 'id' => $payment])->value('amount_paise');
            abort_if($amount === null, 404);
            $existing = DB::table('commercial_billing_ledger')->where(['payment_id' => $payment, 'entry_type' => 'reversal'])->first();
            if ($existing !== null) {
                abort_unless($existing->reason === $reason, 409);
            } else {
                $this->entry($invoice, $payment, 'reversal', -(int) $amount, $reason);
            }

            return response()->json($this->balance($row, $this->paid($invoice)));
        }, attempts: 3);
    }

    /** Reminder history for an invoice. */
    public function reminders(string $invoice, BillingReminder $reminder): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $row = $this->findInvoice($invoice, false);
        $history = collect($reminder->history($invoice));

        return response()->json(['invoice' => $this->balance($row, $this->paid($invoice)), 'history' => $history])
            ->header('Cache-Control', 'no-store');
    }

    /** Record a governed billing reminder for the invoice if its policy and lead time allow. */
    public function sendReminder(Request $request, string $invoice, BillingReminder $reminder): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $result = $reminder->record($invoice, Auth::id() ?? throw new \RuntimeException('No authenticated user.'));
        $status = $result['duplicate'] ? 200 : 201;

        return response()->json($result, $status)->header('Cache-Control', 'no-store');
    }

    private function findInvoice(string $invoice, bool $lock): object
    {
        $row = DB::table('commercial_invoices')->where('id', $invoice)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function paid(string $invoice): int
    {
        return (int) DB::table('commercial_billing_ledger')->where('invoice_id', $invoice)->sum('delta_paise');
    }

    private function balance(object $invoice, int $paid): array
    {
        $amount = (int) $invoice->amount_paise;
        $status = match (true) {
            $amount === $paid => 'Paid',
            $invoice->due_on < now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString() => 'Past Due',
            $paid > 0 => 'Partially Paid',
            default => 'Issued/Open',
        };

        return [...(array) $invoice, 'currency' => GlobalBusinessSettings::CURRENCY, 'status' => $status, 'paid_paise' => $paid, 'outstanding_paise' => $amount - $paid];
    }

    private function entry(string $invoice, string $payment, string $type, int $amount, ?string $reason = null): void
    {
        DB::table('commercial_billing_ledger')->insert([
            'id' => Str::uuid7()->toString(), 'invoice_id' => $invoice, 'payment_id' => $payment, 'actor_id' => Auth::id(),
            'entry_type' => $type, 'delta_paise' => $amount, 'reason' => $reason, 'recorded_at' => now(),
        ]);
        $this->audit('commercial.payment_'.$type, $payment, ['invoice_id' => $invoice, 'delta_paise' => $amount, 'reason' => $reason]);
    }

    private function audit(string $action, string $subject, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'subject_id' => $subject,
            'actor_id' => Auth::id(), 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
