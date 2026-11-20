<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
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
    public function __construct(private readonly MainPortalAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $data = $request->validate(['section' => 'required|in:accounts,plans,versions,subscriptions,entitlements']);
        $tables = [
            'accounts' => 'commercial_accounts', 'plans' => 'commercial_plans', 'versions' => 'commercial_plan_versions',
            'subscriptions' => 'commercial_subscriptions', 'entitlements' => 'commercial_entitlement_definitions',
        ];
        $section = $data['section'];
        $result = DB::table($tables[$section])->orderBy($section === 'entitlements' ? 'code' : 'id')->simplePaginate(30);
        $coverage = $section === 'subscriptions'
            ? DB::table('commercial_subscription_coverage')->whereIn('subscription_id', $result->getCollection()->pluck('id'))
                ->orderBy('business_id')->get()->groupBy('subscription_id')
            : collect();
        $result->through(function (object $row) use ($section, $coverage): object {
            if ($section === 'versions') {
                $row->currency = 'INR';
                $row->entitlements = json_decode($row->entitlements, true, flags: JSON_THROW_ON_ERROR);
                $row->published_at = CarbonImmutable::parse($row->published_at, 'UTC')->utc()->toISOString();
            }
            if ($section === 'subscriptions') {
                $row->covered_business_ids = $coverage->get($row->id, collect())->pluck('business_id')->all();
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
            'starts_on' => 'required|date_format:Y-m-d', 'renewal_mode' => 'required|in:manual',
            'coverage_mode' => 'required|in:organization,selected,network,future_network',
            'business_ids' => 'required_if:coverage_mode,selected|prohibited_unless:coverage_mode,selected|array|list|max:1000',
            'business_ids.*' => 'required|uuid|distinct:ignore_case',
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $account = DB::table('commercial_accounts')->where('id', $data['account_id'])->first();
            abort_if($account === null, 404);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            abort_unless($tenant !== null && $tenant->provider_id === $account->provider_id, 409, 'This agreement requires a separate commercial transition.');
            $version = DB::table('commercial_plan_versions as version')->join('commercial_plans as plan', 'plan.id', '=', 'version.plan_id')
                ->where('version.id', $data['plan_version_id'])->where('plan.provider_id', $account->provider_id)
                ->where('plan.family', 'tenant')->first(['version.term_days']);
            abort_if($version === null, 422, 'Select a published Tenant Plan Version from the account provider.');

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
                'starts_on' => $start->toDateString(), 'ends_before' => $start->addDays((int) $version->term_days)->toDateString(),
            ]);
            foreach (array_chunk($coverage, 250) as $group) {
                DB::table('commercial_subscription_coverage')->insert(array_map(
                    static fn (string $business): array => ['subscription_id' => $id, 'business_id' => $business], $group,
                ));
            }
            $this->audit('commercial.subscription_created', $id, ['account_id' => $account->id, 'coverage_mode' => $mode]);

            return response()->json(['id' => $id, 'state' => 'Pending Activation'], 201);
        }, attempts: 3);
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
