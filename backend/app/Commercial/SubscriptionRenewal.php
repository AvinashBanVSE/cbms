<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use App\Jobs\ProcessSubscriptionRenewal;
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
        $accounts = DB::table('commercial_subscriptions as term')
            ->join('commercial_accounts as account', 'account.id', '=', 'term.account_id')
            ->join('customer_tenants as tenant', function ($join): void {
                $join->on('tenant.id', '=', 'account.tenant_id')->on('tenant.provider_id', '=', 'account.provider_id');
            })
            ->where('term.renewal_mode', 'automatic')
            ->select('term.id', 'term.renewed_from_id', 'term.account_id', 'account.tenant_id', 'account.provider_id',
                'term.ends_before', 'term.renewal_lead_days');
        $candidates = (clone $accounts)->where('term.state', 'Active')
            ->where('term.starts_on', '<=', $today)->where('term.ends_before', '>', $date->subDays(3660)->toDateString())
            ->whereNotExists(fn ($query) => $query->from('commercial_subscriptions as renewal')->select('renewal.id')
                ->whereColumn('renewal.renewed_from_id', 'term.id'));
        foreach ($candidates->lazyById(100, 'term.id', 'id') as $term) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $term->ends_before, GlobalBusinessSettings::BUSINESS_TIME_ZONE);
            if ($date->gte($start->subDays((int) $term->renewal_lead_days))) {
                ProcessSubscriptionRenewal::dispatch($term->id, $term->account_id, $term->tenant_id, $term->provider_id, null);
            }
        }

        $pending = $accounts->whereIn('term.state', ['Pending Activation', 'Grace Period'])
            ->whereNotNull('term.renewed_from_id')->where('term.starts_on', '<=', $today)->where('term.ends_before', '>', $today);
        foreach ($pending->lazyById(100, 'term.id', 'id') as $term) {
            ProcessSubscriptionRenewal::dispatch($term->id, $term->account_id, $term->tenant_id, $term->provider_id, $term->renewed_from_id);
        }
    }

    public function processAutomatic(ProcessSubscriptionRenewal $job): void
    {
        if ($job->predecessor === null) {
            $this->prepare($job->subscription, job: $job);

            return;
        }

        $this->applyRenewalPolicy($job);
    }

    private function prepare(string $subscription, ?string $version = null, ?string $reason = null, ?ProcessSubscriptionRenewal $job = null): ?array
    {
        $automatic = $version === null;

        return DB::transaction(function () use ($subscription, $version, $reason, $automatic, $job): ?array {
            $account = $this->account($subscription);
            if ($job !== null && ! $this->matchesJob($account, $job)) {
                return null;
            }
            abort_if($account === null, 404);
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            $organization = DB::table('business_identities')->where('id', $tenant->organization_id)->lockForUpdate()->first();
            $previous = DB::table('commercial_subscriptions')->where(['id' => $subscription, 'account_id' => $account->id])->lockForUpdate()->first();
            if ($previous === null) {
                abort_unless($automatic, 409, 'The Subscription context has changed.');

                return null;
            }
            $existing = DB::table('commercial_subscriptions')->where('renewed_from_id', $subscription)->lockForUpdate()->first();
            if ($automatic && ($existing !== null || $previous->renewal_mode !== 'automatic')) {
                return null;
            }
            if ($tenant->provider_id !== $account->provider_id) {
                abort_unless($automatic, 409, 'This agreement requires a separate commercial transition.');

                return null;
            }
            if ($existing !== null) {
                abort_unless($existing->account_id === $account->id && $existing->plan_version_id === $version, 409, 'A renewal already exists for this term.');

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
                'tenant_id' => $tenant->id, 'provider_id' => $account->provider_id,
            ]);
            if ($automatic) {
                SubscriptionAddOns::carryForward($subscription, $id);
                $issuedOn = $today->toDateString();
                SubscriptionTerms::issueInvoice((object) $terms, $issuedOn, max($issuedOn, $startsOn), $actor);
            }

            return [$id, $startsOn, true];
        }, attempts: 3);
    }

    private function applyRenewalPolicy(ProcessSubscriptionRenewal $job): void
    {
        DB::transaction(function () use ($job): void {
            $renewal = $job->subscription;
            $previous = $job->predecessor;
            $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
            $account = $this->account($renewal);
            if (! $this->matchesJob($account, $job)) {
                return;
            }
            $tenant = DB::table('customer_tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();
            $organization = DB::table('business_identities')->where('id', $tenant->organization_id)->lockForUpdate()->first();
            $terms = DB::table('commercial_subscriptions')->where('account_id', $account->id);
            $prior = (clone $terms)->where('id', $previous)->lockForUpdate()->first();
            $term = $terms->where('id', $renewal)->where('renewed_from_id', $previous)
                ->where('renewal_mode', 'automatic')->lockForUpdate()->first();
            if ($prior === null || $term === null || $tenant->provider_id !== $account->provider_id
                || $organization->operational_state === 'Closed' || $prior->state !== 'Active'
                || ! in_array($term->state, ['Pending Activation', 'Grace Period'], true)
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
                'tenant_id' => $tenant->id, 'provider_id' => $account->provider_id,
            ]);
        }, attempts: 3);
    }

    private function matchesJob(?object $account, ProcessSubscriptionRenewal $job): bool
    {
        return $account !== null && $account->id === $job->account
            && $account->tenant_id === $job->tenant && $account->provider_id === $job->provider;
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
