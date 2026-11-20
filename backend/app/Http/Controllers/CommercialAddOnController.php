<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Commercial\SubscriptionAddOns;
use App\Commercial\SubscriptionTerms;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialAddOnController
{
    public function __construct(private readonly MainPortalAccess $access) {}

    public function store(Request $request): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $input = $request->validate([
            'provider_id' => 'required|uuid', 'family' => 'required|in:operator,tenant,dealer',
            'name' => 'required|string|max:100',
        ]);
        abort_unless(DB::table('business_identities')->where('id', $input['provider_id'])
            ->whereIn('kind', ['Main Portal', 'Sub-Portal'])->where('operational_state', 'Active')->exists(), 422);
        $id = Str::uuid7()->toString();
        try {
            DB::transaction(function () use ($id, $input): void {
                DB::table('commercial_add_ons')->insert(['id' => $id, ...$input, 'type' => 'feature']);
                $this->record('commercial.add_on_created', $id, []);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This Add-on already exists in the provider and Plan family.');
        }

        return response()->json(['id' => $id], 201);
    }

    public function publish(Request $request, string $addOn): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $input = $request->validate([
            'plan_version_id' => 'required|uuid',
            'amount_paise' => 'required|integer|min:0|max:999999999999',
            'entitlements' => 'required|array|list|min:1|max:100',
            'entitlements.*' => 'required|string|distinct|exists:commercial_entitlement_definitions,code',
        ]);

        return DB::transaction(function () use ($addOn, $input): JsonResponse {
            $record = DB::table('commercial_add_ons')->where('id', $addOn)->lockForUpdate()->first();
            abort_if($record === null, 404);
            abort_unless(DB::table('commercial_plan_versions as version')
                ->join('commercial_plans as plan', 'plan.id', '=', 'version.plan_id')
                ->where('version.id', $input['plan_version_id'])->where('plan.provider_id', $record->provider_id)
                ->where('plan.family', $record->family)->exists(), 422, 'Select a Plan Version from the Add-on provider and family.');
            $features = DB::table('commercial_entitlement_definitions')->whereIn('code', $input['entitlements'])
                ->where(['type' => 'feature', 'composition' => 'any'])->orderBy('code')->get(['code', 'type', 'composition']);
            abort_unless($features->count() === count($input['entitlements']), 422, 'Select Feature Entitlements.');
            $version = 1 + (int) DB::table('commercial_add_on_versions')->where('add_on_id', $addOn)->max('version');
            $id = Str::uuid7()->toString();
            DB::table('commercial_add_on_versions')->insert([
                'id' => $id, 'add_on_id' => $addOn, 'version' => $version, 'plan_version_id' => $input['plan_version_id'],
                'amount_paise' => $input['amount_paise'], 'entitlements' => $features->toJson(JSON_THROW_ON_ERROR), 'published_at' => now(),
            ]);
            $this->record('commercial.add_on_version_published', $id, ['add_on_id' => $addOn, 'version' => $version]);

            return response()->json(['id' => $id, 'version' => $version], 201);
        }, attempts: 3);
    }

    public function attach(Request $request, string $subscription): JsonResponse
    {
        $this->access->requirePermission('commercial.manage');
        $input = $request->validate(['add_on_version_id' => 'required|uuid', 'business_id' => 'required|uuid']);

        return DB::transaction(function () use ($subscription, $input): JsonResponse {
            $account = DB::table('commercial_subscriptions as term')->join('commercial_accounts as account', 'account.id', '=', 'term.account_id')
                ->where('term.id', $subscription)->first(['account.tenant_id', 'account.provider_id']);
            abort_if($account === null, 404);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            abort_unless($account->provider_id === $tenant->provider_id, 409, 'This agreement requires a separate commercial transition.');
            $term = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
            $version = DB::table('commercial_add_on_versions')->where('id', $input['add_on_version_id'])
                ->where('plan_version_id', $term->plan_version_id)->first();
            abort_if($version === null, 422, 'Select an Add-on Version compatible with this term’s Plan Version.');
            SubscriptionAddOns::requireEligibleCoverage($subscription, $input['business_id'], $tenant->organization_id);

            $assignment = ['subscription_id' => $subscription, 'business_id' => $input['business_id'], 'add_on_id' => $version->add_on_id];
            $existing = DB::table('commercial_subscription_add_ons')->where($assignment)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($existing->add_on_version_id === $version->id, 409, 'Another version of this Add-on is already attached for this business.');

                return response()->json($existing);
            }
            abort_if($term->state !== 'Pending Activation' || $term->ends_before <= now('Asia/Kolkata')->toDateString()
                || SubscriptionTerms::invoiceForSettlement($subscription) !== null, 409, 'Attach Add-ons to a pending term before its invoice is issued.');
            abort_if(SubscriptionAddOns::total($term) + (int) $version->amount_paise > 999999999999, 422, 'The term amount exceeds the supported invoice amount.');
            $assignment['add_on_version_id'] = $version->id;
            DB::table('commercial_subscription_add_ons')->insert($assignment);
            $this->record('commercial.add_on_attached', $subscription, $assignment);

            return response()->json($assignment, 201);
        }, attempts: 3);
    }

    private function record(string $action, string $subject, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(), 'subject_id' => $subject,
            'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
