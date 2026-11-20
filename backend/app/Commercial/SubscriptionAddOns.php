<?php

declare(strict_types=1);

namespace App\Commercial;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubscriptionAddOns
{
    public static function applyCapacityQuantities(Collection $definitions, array $quantities): void
    {
        $capacities = $definitions->where('type', 'capacity');
        $codes = $capacities->pluck('code')->all();
        if (array_diff($codes, array_keys($quantities)) || array_diff(array_keys($quantities), $codes)) {
            throw ValidationException::withMessages(['capacities' => 'Provide one quantity for each selected capacity entitlement.']);
        }
        foreach ($capacities as $definition) {
            $definition->quantity = (int) $quantities[$definition->code];
        }
    }

    public static function requireEligibleCoverage(string $term, string $business, string $organization): void
    {
        $coverage = DB::table('commercial_subscription_coverage as coverage')
            ->join('business_identities as business', 'business.id', '=', 'coverage.business_id')
            ->where('coverage.subscription_id', $term)->where('coverage.business_id', $business)
            ->where('business.operational_state', '!=', 'Closed')->where(function ($query) use ($organization): void {
                $query->where('business.id', $organization)->orWhere(function ($children) use ($organization): void {
                    $children->where('business.parent_id', $organization)->whereIn('business.kind', ['Distributor', 'Shop']);
                });
            })->lockForUpdate()->first(['coverage.business_id']);
        abort_if($coverage === null, 422, 'Select an eligible business in the Subscription coverage.');
    }

    public static function total(object $term): int
    {
        $base = (int) DB::table('commercial_plan_versions')->where('id', $term->plan_version_id)->value('amount_paise');
        $addOns = DB::table('commercial_subscription_add_ons as assignment')
            ->join('commercial_add_on_versions as version', 'version.id', '=', 'assignment.add_on_version_id')
            ->where('assignment.subscription_id', $term->id)->lockForUpdate()->pluck('version.amount_paise')->sum();

        return $base + (int) $addOns;
    }

    public static function carryForward(string $previous, string $renewal): void
    {
        $assignments = DB::table('commercial_subscription_add_ons as assignment')
            ->join('commercial_subscription_coverage as coverage', 'coverage.business_id', '=', 'assignment.business_id')
            ->where('assignment.subscription_id', $previous)->where('coverage.subscription_id', $renewal)
            ->selectRaw('coverage.subscription_id, assignment.business_id, assignment.add_on_id, assignment.add_on_version_id');
        DB::table('commercial_subscription_add_ons')->insertUsing(
            ['subscription_id', 'business_id', 'add_on_id', 'add_on_version_id'], $assignments,
        );
    }
}
