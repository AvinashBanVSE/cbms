<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Commercial\SubscriptionAddOns;
use App\Commercial\SubscriptionAmendment;
use App\Commercial\SubscriptionCoverage;
use App\Commercial\SubscriptionRenewal;
use App\Commercial\SubscriptionTerms;
use App\Commercial\UsageMeasurement;
use App\Configuration\GlobalBusinessSettings;
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
        private readonly SubscriptionAmendment $amendments,
        private readonly UsageMeasurement $usage,
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
        $today = now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
        $result->through(function (object $row) use ($section, $coverage, $addOns, $today): object {
            if (in_array($section, ['versions', 'add_on_versions'], true)) {
                $row->currency = GlobalBusinessSettings::CURRENCY;
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
        $type = $request->validate([
            'type' => 'sometimes|required|in:feature,capacity',
            'composition' => 'required_if:type,capacity|missing_unless:type,capacity|in:additive,maximum',
        ]);
        try {
            DB::transaction(function () use ($data, $type): void {
                DB::table('commercial_entitlement_definitions')->insert([
                    ...$data, 'type' => $type['type'] ?? 'feature', 'composition' => $type['composition'] ?? 'any',
                ]);
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
            'trial_days' => 'sometimes|required|integer|min:1|lte:term_days',
            'entitlements' => 'present|array|list|max:100',
            'entitlements.*' => 'required|string|distinct|exists:commercial_entitlement_definitions,code',
            'capacities' => 'sometimes|array|max:100',
            'capacities.*' => 'required|integer|min:1|max:1000000000',
        ]);

        return DB::transaction(function () use ($plan, $data): JsonResponse {
            $record = DB::table('commercial_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_if($record === null, 404);
            $version = 1 + (int) DB::table('commercial_plan_versions')->where('plan_id', $plan)->max('version');
            $definitions = DB::table('commercial_entitlement_definitions')->whereIn('code', $data['entitlements'])
                ->orderBy('code')->get(['code', 'type', 'composition']);
            SubscriptionAddOns::applyCapacityQuantities($definitions, $data['capacities'] ?? []);
            $id = Str::uuid7()->toString();
            DB::table('commercial_plan_versions')->insert([
                'id' => $id, 'plan_id' => $plan, 'version' => $version, 'amount_paise' => $data['amount_paise'],
                'term_days' => $data['term_days'], 'trial_days' => $data['trial_days'] ?? null, 'entitlements' => $definitions->toJson(JSON_THROW_ON_ERROR), 'published_at' => now(),
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
            'trial_reason' => 'sometimes|required|string|max:500',
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
            $trial = isset($data['trial_reason']);
            if ($trial) {
                $trialDays = DB::table('commercial_plan_versions')->where('id', $data['plan_version_id'])->value('trial_days');
                abort_unless($trialDays !== null && $data['renewal_mode'] === 'manual', 422, 'A trial requires a published trial duration and manual renewal mode.');
                $termDays = (int) $trialDays;
            }
            $state = $trial ? 'Trial' : 'Pending Activation';
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

            $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['starts_on'], GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            $id = Str::uuid7()->toString();
            DB::table('commercial_subscriptions')->insert([
                'id' => $id, 'account_id' => $account->id, 'plan_version_id' => $data['plan_version_id'],
                'state' => $state, 'renewal_mode' => $data['renewal_mode'], 'coverage_mode' => $mode,
                'renewal_lead_days' => $leadDays,
                'renewal_grace_days' => $graceDays, 'renewal_grace_policy' => $data['renewal_grace_policy'] ?? null,
                'starts_on' => $start->toDateString(), 'ends_before' => $start->addDays($termDays)->toDateString(),
            ]);
            SubscriptionCoverage::assign($id, $coverage);
            $this->audit('commercial.subscription_created', $id, [
                'account_id' => $account->id, 'coverage_mode' => $mode,
                ...($trial ? ['trial_reason' => $data['trial_reason'], 'trial_days' => $termDays] : []),
            ]);

            return response()->json(['id' => $id, 'state' => $state], 201);
        }, attempts: 3);
    }

    public function transition(Request $request, string $subscription): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'action' => 'required|in:activate,suspend,cancel',
            'expected_state' => 'required|in:Pending Activation,Trial,Active,Grace Period,Suspended',
            'reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($subscription, $data): JsonResponse {
            $account = DB::table('commercial_subscriptions as subscription')
                ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
                ->where('subscription.id', $subscription)->first(['account.tenant_id', 'account.provider_id']);
            abort_if($account === null, 404);
            $provider = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->value('provider_id');
            $record = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
            $today = now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
            abort_unless($record->state === $data['expected_state'], 409, 'The Subscription state changed. Reload the commercial records.');
            abort_if($record->ends_before <= $today, 409, 'The Subscription term has ended.');

            [$allowed, $next] = match ($data['action']) {
                'activate' => [['Pending Activation', 'Grace Period', 'Suspended'], 'Active'],
                'suspend' => [['Active', 'Grace Period'], 'Suspended'],
                'cancel' => [['Pending Activation', 'Trial', 'Active', 'Grace Period', 'Suspended'], 'Cancelled'],
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

    /** Explicitly effective-dated Plan change (§14.9): upgrade/downgrade, immediate or next-cycle. */
    public function amend(Request $request, string $subscription): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'plan_version_id' => 'required|uuid',
            'change_type' => 'required|in:upgrade,downgrade',
            'timing' => 'required|in:immediate,next_cycle',
            'effective_date' => 'required_if:timing,immediate|missing_unless:timing,immediate|date_format:Y-m-d',
            'reason' => 'required|string|max:500',
        ]);

        return response()->json($this->amendments->change(
            $subscription, $data['plan_version_id'], $data['change_type'], $data['timing'],
            $data['effective_date'] ?? '', $data['reason'],
        ), 201)->header('Cache-Control', 'no-store');
    }

    public function effectiveEntitlements(string $business): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $identity = DB::table('business_identities')->where('id', $business)
            ->whereIn('kind', ['Organization', 'Distributor', 'Shop'])->first(['id', 'kind', 'parent_id']);
        abort_if($identity === null, 404);
        $organization = $identity->kind === 'Organization' ? $identity->id : $identity->parent_id;
        $today = now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
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
        $plans = DB::table('commercial_plan_versions as version')
            ->joinSub($eligibleTerms->clone()->select('subscription.plan_version_id', 'subscription.state'), 'term', 'term.plan_version_id', '=', 'version.id')
            ->select('version.entitlements', 'term.state');
        $addOns = DB::table('commercial_subscription_add_ons as assignment')
            ->join('commercial_add_on_versions as version', 'version.id', '=', 'assignment.add_on_version_id')
            ->joinSub($eligibleTerms->select('subscription.id', 'subscription.state'), 'term', 'term.id', '=', 'assignment.subscription_id')
            ->where('assignment.business_id', $business)->select('version.entitlements', 'term.state');

        $features = [];
        $capacities = [];
        foreach ($plans->unionAll($addOns)->cursor() as $source) {
            foreach (json_decode($source->entitlements, true, flags: JSON_THROW_ON_ERROR) as $entitlement) {
                if ($entitlement['type'] === 'feature' && $entitlement['composition'] === 'any') {
                    $features[$entitlement['code']] = true;
                }
                if ($entitlement['type'] === 'capacity' && $source->state !== 'Grace Period') {
                    $code = $entitlement['code'];
                    $current = $capacities[$code] ?? 0;
                    $capacities[$code] = match ($entitlement['composition']) {
                        'additive' => $current + $entitlement['quantity'],
                        'maximum' => max($current, $entitlement['quantity']),
                    };
                }
            }
        }
        ksort($features, SORT_STRING);

        ksort($capacities, SORT_STRING);

        return response()->json([
            'business_id' => $business, 'feature_codes' => array_keys($features),
            ...($capacities === [] ? [] : ['capacities' => $capacities]),
        ])
            ->header('Cache-Control', 'no-store');
    }

    /** Record consumption against a Usage Limit for a covered business (§14.5). */
    public function recordUsage(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate([
            'usage_limit' => 'required|string|exists:commercial_usage_limits,code',
            'business_id' => 'required|uuid',
            'quantity' => 'required|integer|min:0',
            'idempotency_key' => 'sometimes|nullable|string|max:80',
        ]);

        return response()->json($this->usage->record(
            $data['usage_limit'], $data['business_id'], (int) $data['quantity'],
            ['idempotency_key' => $data['idempotency_key'] ?? null],
        ), 201)->header('Cache-Control', 'no-store');
    }

    /** Authorized usage status (consumed, threshold, period, limit) for a business (§14.5). */
    public function usageStatus(string $business): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');

        return response()->json([
            'business_id' => $business, 'usage' => $this->usage->status($business),
        ])->header('Cache-Control', 'no-store');
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
