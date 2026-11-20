<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use Illuminate\Support\Facades\DB;

final class SubscriptionCoverage
{
    public static function assign(string $subscription, array $coverage): void
    {
        foreach (array_chunk($coverage, 250) as $group) {
            DB::table('commercial_subscription_coverage')->insert(array_map(
                static fn (string $business): array => ['subscription_id' => $subscription, 'business_id' => $business], $group,
            ));
        }
    }

    public static function includeNewChild(string $organization, string $child): void
    {
        $today = now(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
        DB::table('commercial_subscriptions as subscription')
            ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
            ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
            ->where('tenant.organization_id', $organization)->where('subscription.coverage_mode', 'future_network')
            ->where('subscription.ends_before', '>', $today)->whereNotIn('subscription.state', ['Cancelled', 'Expired'])
            ->select('subscription.id')->chunkById(200, function ($subscriptions) use ($child): void {
                DB::table('commercial_subscription_coverage')->insert($subscriptions->map(
                    static fn ($subscription): array => ['subscription_id' => $subscription->id, 'business_id' => $child],
                )->all());
            }, 'subscription.id', 'id');
    }
}
