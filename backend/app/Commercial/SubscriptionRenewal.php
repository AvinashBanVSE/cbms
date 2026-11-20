<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionRenewal
{
    public function manual(string $subscription, string $version, string $reason): array
    {
        return $this->prepare($subscription, $version, $reason);
    }

    public function runAutomatic(): void
    {
        $date = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE);
        $today = $date->toDateString();
        $candidates = DB::table('commercial_subscriptions as term')->where(['term.renewal_mode' => 'automatic', 'term.state' => 'Active'])
            ->where('term.starts_on', '<=', $today)->where('term.ends_before', '>', $date->subDays(3660)->toDateString())
            ->whereNotExists(fn ($query) => $query->from('commercial_subscriptions as renewal')->select('renewal.id')
                ->whereColumn('renewal.renewed_from_id', 'term.id'));
        foreach ($candidates->select('term.id')->lazyById(100, 'term.id', 'id') as $term) {
            $this->prepare($term->id);
        }

        $pending = DB::table('commercial_subscriptions')->where('renewal_mode', 'automatic')
            ->whereIn('state', ['Pending Activation', 'Grace Period'])
            ->whereNotNull('renewed_from_id')->where('starts_on', '<=', $today)->where('ends_before', '>', $today);
        foreach ($pending->select('id', 'renewed_from_id')->lazyById(100) as $term) {
            $this->applyRenewalPolicy($term->id, $term->renewed_from_id, $today);
        }
    }

    private function prepare(string $subscription, ?string $version = null, ?string $reason = null): ?array
    {
        $automatic = $version === null;

        return DB::transaction(function () use ($subscription, $version, $reason, $automatic): ?array {
            $account = $this->account($subscription);
            abort_if($account === null, 404);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            $organization = DB::table('business_identities')->where('id', $tenant->organization_id)->lockForUpdate()->first();
            $previous = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
            $existing = DB::table('commercial_subscriptions')->where('renewed_from_id', $subscription)->lockForUpdate()->first();
            if ($automatic && ($existing !== null || $previous->renewal_mode !== 'automatic')) {
                return null;
            }
            if ($tenant->provider_id !== $account->provider_id) {
                abort_unless($automatic, 409, 'This agreement requires a separate commercial transition.');

                return null;
            }
            if ($existing !== null) {
                abort_unless($existing->plan_version_id === $version, 409, 'A renewal already exists for this term.');

                return [$existing->id, $existing->starts_on, false];
            }
            if ($previous->state !== 'Active' || $organization->operational_state === 'Closed') {
                abort_unless($automatic, 409, 'This Subscription cannot be renewed.');

                return null;
            }

            $version ??= $previous->plan_version_id;
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $previous->ends_before, GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            $end = $start->addDays(SubscriptionTerms::duration($account->provider_id, $version));
            $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            if ($automatic && $today->lt($start->subDays((int) $previous->renewal_lead_days))) {
                return null;
            }
            if ($today->gte($end)) {
                abort_unless($automatic, 409, 'The next term has already ended. Create a new agreement.');

                return null;
            }
            $coverage = $this->coverage($previous, $organization->id);
            if ($coverage === []) {
                abort_unless($automatic, 409, 'There are no eligible businesses in the existing coverage.');

                return null;
            }

            $id = Str::uuid7()->toString();
            $startsOn = $start->toDateString();
            $graceDays = $automatic ? $previous->renewal_grace_days : null;
            $terms = [
                'id' => $id, 'account_id' => $account->id, 'plan_version_id' => $version,
                'state' => 'Pending Activation', 'renewal_mode' => $automatic ? 'automatic' : 'manual',
                'renewal_lead_days' => $automatic ? $previous->renewal_lead_days : null, 'coverage_mode' => $previous->coverage_mode,
                'renewal_grace_days' => $graceDays, 'renewal_grace_policy' => $automatic ? $previous->renewal_grace_policy : null,
                'grace_ends_before' => $graceDays === null ? null : $start->addDays((int) $graceDays)->toDateString(),
                'starts_on' => $startsOn, 'ends_before' => $end->toDateString(), 'renewed_from_id' => $subscription,
            ];
            DB::table('commercial_subscriptions')->insert($terms);
            SubscriptionCoverage::assign($id, $coverage);
            $actor = $automatic ? null : Auth::id();
            $this->record('commercial.subscription_renewed', $id, $actor, [
                'previous_term_id' => $subscription, 'previous_plan_version_id' => $previous->plan_version_id,
                'plan_version_id' => $version, 'starts_on' => $startsOn, 'reason' => $reason ?? 'Scheduled automatic renewal',
            ]);
            if ($automatic) {
                SubscriptionAddOns::carryForward($subscription, $id);
                $issuedOn = $today->toDateString();
                SubscriptionTerms::issueInvoice((object) $terms, $issuedOn, max($issuedOn, $startsOn), $actor);
            }

            return [$id, $startsOn, true];
        }, attempts: 3);
    }

    private function applyRenewalPolicy(string $renewal, string $previous, string $today): void
    {
        DB::transaction(function () use ($renewal, $previous, $today): void {
            $account = $this->account($renewal);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            $organization = DB::table('business_identities')->where('id', $tenant->organization_id)->lockForUpdate()->first();
            $prior = DB::table('commercial_subscriptions')->where('id', $previous)->lockForUpdate()->first();
            $term = DB::table('commercial_subscriptions')->where('id', $renewal)->lockForUpdate()->first();
            if ($tenant->provider_id !== $account->provider_id || $organization->operational_state === 'Closed'
                || $prior->state !== 'Active' || ! in_array($term->state, ['Pending Activation', 'Grace Period'], true)
                || $term->starts_on > $today || $term->ends_before <= $today) {
                return;
            }
            $invoice = SubscriptionTerms::invoiceForSettlement($renewal);
            if ($invoice === null) {
                return;
            }
            $next = match (true) {
                SubscriptionTerms::isSettled($invoice) => 'Active',
                $term->grace_ends_before === null => $term->state,
                $today < $term->grace_ends_before => 'Grace Period',
                default => 'Suspended',
            };
            if ($next === $term->state) {
                return;
            }
            DB::table('commercial_subscriptions')->where('id', $renewal)->update(['state' => $next]);
            $this->record('commercial.subscription_state_changed', $renewal, null, [
                'from' => $term->state, 'to' => $next, 'reason' => 'Scheduled renewal policy',
            ]);
        }, attempts: 3);
    }

    private function account(string $subscription): ?object
    {
        return DB::table('commercial_subscriptions as subscription')
            ->join('commercial_accounts as account', 'account.id', '=', 'subscription.account_id')
            ->where('subscription.id', $subscription)->first(['account.id', 'account.tenant_id', 'account.provider_id']);
    }

    private function coverage(object $previous, string $organization): array
    {
        $businesses = DB::table('business_identities as business')->where('business.operational_state', '!=', 'Closed')
            ->where(function ($query) use ($organization): void {
                $query->where('business.id', $organization)->orWhere(function ($children) use ($organization): void {
                    $children->where('business.parent_id', $organization)->whereIn('business.kind', ['Distributor', 'Shop']);
                });
            });
        if ($previous->coverage_mode !== 'future_network') {
            $businesses->join('commercial_subscription_coverage as coverage', 'coverage.business_id', '=', 'business.id')
                ->where('coverage.subscription_id', $previous->id);
        }

        return $businesses->lockForUpdate()->pluck('business.id')->all();
    }

    private function record(string $action, string $id, ?string $actor, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => $actor,
            'subject_id' => $id, 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
