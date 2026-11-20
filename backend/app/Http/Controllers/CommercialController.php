<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Commercial\SubscriptionCoverage;
use App\Commercial\SubscriptionRenewal;
use App\Commercial\SubscriptionTerms;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommercialController
{
    public function __construct(
        private readonly MainPortalAccess $access,
        private readonly SubscriptionRenewal $renewals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate(['section' => 'required|in:accounts,plans,versions,subscriptions,entitlements,add_ons,add_on_versions']);
        $tables = [
            'accounts' => 'commercial_accounts', 'plans' => 'commercial_plans', 'versions' => 'commercial_plan_versions',
            'subscriptions' => 'commercial_subscriptions', 'entitlements' => 'commercial_entitlement_definitions',
            'add_ons' => 'commercial_add_ons', 'add_on_versions' => 'commercial_add_on_versions',
        ];
        $section = $data['section'];
        $query = DB::table($tables[$section])->orderBy($section === 'entitlements' ? 'code' : 'id');
        if ($section === 'subscriptions') {
            $query->select('commercial_subscriptions.*')->addSelect([
                'renewal_id' => DB::table('commercial_subscriptions as renewal')->select('id')
                    ->whereColumn('renewal.renewed_from_id', 'commercial_subscriptions.id'),
                'invoice_id' => DB::table('commercial_invoices')->select('id')
                    ->whereColumn('commercial_invoices.subscription_id', 'commercial_subscriptions.id'),
            ]);
        }
        $result = $query->simplePaginate(30);
        $coverage = $section === 'subscriptions'
            ? DB::table('commercial_subscription_coverage')->whereIn('subscription_id', $result->getCollection()->pluck('id'))
                ->orderBy('business_id')->get()->groupBy('subscription_id')
            : collect();
        $addOns = $section === 'subscriptions'
            ? DB::table('commercial_subscription_add_ons')->whereIn('subscription_id', $result->getCollection()->pluck('id'))
                ->orderBy('business_id')->orderBy('add_on_id')->get()->groupBy('subscription_id')
            : collect();
        $today = now('Asia/Kolkata')->toDateString();
        $result->through(function (object $row) use ($section, $coverage, $addOns, $today): object {
            if (in_array($section, ['versions', 'add_on_versions'], true)) {
                $row->currency = 'INR';
                $row->entitlements = json_decode($row->entitlements, true, flags: JSON_THROW_ON_ERROR);
                $row->published_at = CarbonImmutable::parse($row->published_at, 'UTC')->utc()->toISOString();
            }
            if ($section === 'subscriptions') {
                $row->covered_business_ids = $coverage->get($row->id, collect())->pluck('business_id')->all();
                $row->add_ons = $addOns->get($row->id, collect())->all();
                $row->effective_state = match (true) {
                    $row->state !== 'Cancelled' && $row->ends_before <= $today => 'Expired',
                    $row->state === 'Grace Period' && $row->grace_ends_before <= $today => 'Suspended',
                    default => $row->state,
                };
            }

            return $row;
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function account(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate(['tenant_id' => 'required|uuid', 'provider_id' => 'required|uuid', 'billing_party' => 'required|string|max:255']);

        return DB::transaction(function () use ($data): JsonResponse {
            $current = DB::table('customer_tenants')->where('id', $data['tenant_id'])->lockForUpdate()->value('provider_id');
            abort_unless($current === $data['provider_id'], 422, 'Use the current service provider for this Organization.');

            return $this->create('commercial_accounts', $data, 'commercial.account_created');
        }, attempts: 3);
    }

    public function entitlement(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate(['code' => 'required|string|max:80|regex:/^[a-z][a-z0-9_.-]*$/D', 'name' => 'required|string|max:150']);
        try {
            DB::transaction(function () use ($data): void {
                DB::table('commercial_entitlement_definitions')->insert([...$data, 'type' => 'feature', 'composition' => 'any']);
                $this->audit('commercial.entitlement_registered', null, ['code' => $data['code']]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This Entitlement Code already exists.');
        }

        return response()->json(['code' => $data['code']], 201);
    }

    public function plan(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'provider_id' => 'required|uuid', 'name' => 'required|string|max:100',
            'family' => 'required|in:operator,tenant,dealer',
        ]);
        abort_unless(DB::table('business_identities')->where('id', $data['provider_id'])
            ->whereIn('kind', ['Main Portal', 'Sub-Portal'])->where('operational_state', 'Active')->exists(), 422);

        return $this->create('commercial_plans', $data, 'commercial.plan_created');
    }

    public function publish(Request $request, string $plan): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'amount_paise' => 'required|integer|min:0|max:999999999999',
            'term_days' => 'required|integer|min:1|max:3660',
            'entitlements' => 'present|array|list|max:100',
            'entitlements.*' => 'required|string|distinct|exists:commercial_entitlement_definitions,code',
        ]);

        return DB::transaction(function () use ($plan, $data): JsonResponse {
            $record = DB::table('commercial_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_if($record === null, 404);
            $version = 1 + (int) DB::table('commercial_plan_versions')->where('plan_id', $plan)->max('version');
            $definitions = DB::table('commercial_entitlement_definitions')->whereIn('code', $data['entitlements'])
                ->orderBy('code')->get(['code', 'type', 'composition']);
            $id = Str::uuid7()->toString();
            DB::table('commercial_plan_versions')->insert([
                'id' => $id, 'plan_id' => $plan, 'version' => $version, 'amount_paise' => $data['amount_paise'],
                'term_days' => $data['term_days'], 'entitlements' => $definitions->toJson(JSON_THROW_ON_ERROR), 'published_at' => now(),
            ]);
            $this->audit('commercial.plan_version_published', $id, ['plan_id' => $plan, 'version' => $version]);

            return response()->json(['id' => $id, 'version' => $version], 201);
        }, attempts: 3);
    }

    public function subscription(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'account_id' => 'required|uuid', 'plan_version_id' => 'required|uuid',
            'starts_on' => 'required|date_format:Y-m-d', 'renewal_mode' => 'required|in:manual,automatic',
            'renewal_lead_days' => 'required_if:renewal_mode,automatic|missing_unless:renewal_mode,automatic|integer|min:1|max:3660',
            'renewal_grace_days' => 'required_with:renewal_grace_policy|missing_unless:renewal_mode,automatic|integer|min:1|max:3660',
            'renewal_grace_policy' => 'required_with:renewal_grace_days|missing_unless:renewal_mode,automatic|in:retain_features,block_features',
            'coverage_mode' => 'required|in:organization,selected,network,future_network',
            'business_ids' => 'required_if:coverage_mode,selected|prohibited_unless:coverage_mode,selected|array|list|max:1000',
            'business_ids.*' => 'required|uuid|distinct:ignore_case',
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $account = DB::table('commercial_accounts')->where('id', $data['account_id'])->first();
            abort_if($account === null, 404);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            abort_unless($tenant !== null && $tenant->provider_id === $account->provider_id, 409, 'This agreement requires a separate commercial transition.');
            $termDays = SubscriptionTerms::duration($account->provider_id, $data['plan_version_id']);
            $leadDays = $data['renewal_lead_days'] ?? null;
            if ($leadDays > $termDays) {
                throw ValidationException::withMessages(['renewal_lead_days' => 'The renewal lead time must fit within the term.']);
            }
            $graceDays = $data['renewal_grace_days'] ?? null;
            if ($graceDays >= $termDays) {
                throw ValidationException::withMessages(['renewal_grace_days' => 'The renewal grace period must be shorter than the term.']);
            }

            $mode = $data['coverage_mode'];
            $organization = $tenant->organization_id;
            DB::table('business_identities')->where('id', $organization)->lockForUpdate()->first(['id']);
            $children = DB::table('business_identities')->where('parent_id', $organization)->whereIn('kind', ['Distributor', 'Shop'])->where('operational_state', '!=', 'Closed');
            if ($mode === 'selected') {
                $coverage = $children->whereIn('id', $data['business_ids'])->pluck('id')->all();
                if (count($coverage) !== count($data['business_ids'])) {
                    throw ValidationException::withMessages(['business_ids' => 'Every selected business must belong to this Organization.']);
                }
            } elseif ($mode === 'network' || $mode === 'future_network') {
                $coverage = [$organization, ...$children->pluck('id')->all()];
            } else {
                $coverage = [$organization];
            }

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['starts_on'], 'Asia/Kolkata');
            $id = Str::uuid7()->toString();
            DB::table('commercial_subscriptions')->insert([
                'id' => $id, 'account_id' => $account->id, 'plan_version_id' => $data['plan_version_id'],
                'state' => 'Pending Activation', 'renewal_mode' => $data['renewal_mode'], 'coverage_mode' => $mode,
                'renewal_lead_days' => $leadDays,
                'renewal_grace_days' => $graceDays, 'renewal_grace_policy' => $data['renewal_grace_policy'] ?? null,
                'starts_on' => $start->toDateString(), 'ends_before' => $start->addDays($termDays)->toDateString(),
            ]);
            SubscriptionCoverage::assign($id, $coverage);
            $this->audit('commercial.subscription_created', $id, ['account_id' => $account->id, 'coverage_mode' => $mode]);

            return response()->json(['id' => $id, 'state' => 'Pending Activation'], 201);
        }, attempts: 3);
    }

    public function transition(Request $request, string $subscription): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'action' => 'required|in:activate,suspend,cancel',
            'expected_state' => 'required|in:Pending Activation,Active,Grace Period,Suspended',
            'reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($subscription, $data): JsonResponse {
            $account = DB::table('commercial_subscriptions as subscription')
                ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
                ->where('subscription.id', $subscription)->first(['account.tenant_id', 'account.provider_id']);
            abort_if($account === null, 404);
            $provider = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->value('provider_id');
            $record = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
            $today = now('Asia/Kolkata')->toDateString();
            abort_unless($record->state === $data['expected_state'], 409, 'The Subscription state changed. Reload the commercial records.');
            abort_if($record->ends_before <= $today, 409, 'The Subscription term has ended.');

            [$allowed, $next] = match ($data['action']) {
                'activate' => [['Pending Activation', 'Grace Period', 'Suspended'], 'Active'],
                'suspend' => [['Active', 'Grace Period'], 'Suspended'],
                'cancel' => [['Pending Activation', 'Active', 'Grace Period', 'Suspended'], 'Cancelled'],
            };
            abort_unless(in_array($record->state, $allowed, true), 409, 'This action is unavailable in the current Subscription state.');

            if ($next === 'Active') {
                abort_unless($provider === $account->provider_id, 409, 'This agreement requires a separate commercial transition.');
                abort_if($today < $record->starts_on, 409, 'The Subscription term has yet to start.');
                $invoice = SubscriptionTerms::invoiceForSettlement($subscription);
                abort_if($invoice === null, 409, 'Issue the term invoice before activation.');
                abort_unless(SubscriptionTerms::isSettled($invoice), 409, 'Settle the term invoice before activation.');
            }
            if ($next === 'Cancelled') {
                abort_if(DB::table('commercial_subscriptions')->where('renewed_from_id', $subscription)
                    ->where('state', 'Pending Activation')->lockForUpdate()->first() !== null, 409,
                    'Cancel the upcoming renewal before cancelling this term.');
            }

            DB::table('commercial_subscriptions')->where('id', $subscription)->update(['state' => $next]);
            $this->audit('commercial.subscription_state_changed', $subscription, [
                'from' => $record->state, 'to' => $next, 'reason' => $data['reason'],
            ]);

            return response()->json(['id' => $subscription, 'state' => $next]);
        }, attempts: 3);
    }

    public function renew(Request $request, string $subscription): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate(['plan_version_id' => 'required|uuid', 'reason' => 'required|string|max:500']);

        [$id, $startsOn, $created] = $this->renewals->manual($subscription, $data['plan_version_id'], $data['reason']);

        return response()->json(['id' => $id, 'starts_on' => $startsOn], $created ? 201 : 200);
    }

    public function effectiveEntitlements(string $business): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $identity = DB::table('business_identities')->where('id', $business)
            ->whereIn('kind', ['Organization', 'Distributor', 'Shop'])->first(['id', 'kind', 'parent_id']);
        abort_if($identity === null, 404);
        $organization = $identity->kind === 'Organization' ? $identity->id : $identity->parent_id;
        $today = now('Asia/Kolkata')->toDateString();
        $eligibleTerms = DB::table('commercial_subscription_coverage as coverage')
            ->join('commercial_subscriptions as subscription', 'subscription.id', '=', 'coverage.subscription_id')
            ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->where('tenant.organization_id', $organization)->where('coverage.business_id', $business)
            ->where(function ($query) use ($today): void {
                $query->whereIn('subscription.state', ['Active', 'Trial'])->orWhere(function ($grace) use ($today): void {
                    $grace->where('subscription.state', 'Grace Period')
                        ->where('subscription.renewal_grace_policy', 'retain_features')->where('subscription.grace_ends_before', '>', $today);
                });
            })->where('subscription.starts_on', '<=', $today)
            ->where('subscription.ends_before', '>', $today);
        $plans = DB::table('commercial_plan_versions')->whereIn('id', $eligibleTerms->clone()->select('subscription.plan_version_id'))
            ->select('entitlements');
        $addOns = DB::table('commercial_subscription_add_ons as assignment')
            ->join('commercial_add_on_versions as version', 'version.id', '=', 'assignment.add_on_version_id')
            ->where('assignment.business_id', $business)->whereIn('assignment.subscription_id', $eligibleTerms->select('subscription.id'))
            ->select('version.entitlements');

        $features = [];
        foreach ($plans->unionAll($addOns)->cursor() as $source) {
            foreach (json_decode($source->entitlements, true, flags: JSON_THROW_ON_ERROR) as $entitlement) {
                if ($entitlement['type'] === 'feature' && $entitlement['composition'] === 'any') {
                    $features[$entitlement['code']] = true;
                }
            }
        }
        ksort($features, SORT_STRING);

        return response()->json(['business_id' => $business, 'feature_codes' => array_keys($features)])
            ->header('Cache-Control', 'no-store');
    }

    private function create(string $table, array $data, string $action): JsonResponse
    {
        $id = Str::uuid7()->toString();
        try {
            DB::transaction(function () use ($id, $table, $data, $action): void {
                DB::table($table)->insert(['id' => $id, ...$data]);
                $this->audit($action, $id, []);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This record already exists in the selected provider context.');
        }

        return response()->json(['id' => $id], 201);
    }

    private function audit(string $action, ?string $id, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(),
            'subject_id' => $id, 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
