<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Central policy evaluator (plan §6).
 *
 * Combination rule: union eligible positive grants inside the current context, then intersect
 * with mandatory bounds and scope; explicit prohibitions (denies) win. Unknown inputs deny.
 * Independent entitlement / lifecycle checks are deliberately NOT folded in here (§6).
 *
 * SHADOW MODE: nothing calls this from an enforcement path yet. It is built so the team can
 * compare its decision against the existing enforcement classes before any cutover.
 */
final class AuthorizationEvaluator
{
    /**
     * Effective actions for one business, with a reason and the catalogue version used.
     *
     * Cached (plan §8) behind the `evaluator_decisions_cached` flag. The cache key is
     * versionKey(): any role/scope/deny/deny-exception/direct-grant change bumps the entity
     * version and any catalogue change bumps the catalogue version, so a cached decision is
     * invalidated before its next read and can never outlive a revoke. Reads the authoritative
     * state through the same query path on a cache miss; default-off so nothing changes until
     * the team opts in after shadow comparison.
     */
    public function decide(string $user, string $businessId): AuthorizationDecision
    {
        if (config('authorization.evaluator_decisions_cached', false) !== true) {
            return $this->compute($user, $businessId);
        }

        $key = 'auth.decision.'.sha1($user.'|'.$this->versionKey($businessId));

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($key);
        if ($cached !== null) {
            return new AuthorizationDecision(
                businessId: (string) $cached['business_id'],
                kind: (string) $cached['kind'],
                allowed: (array) $cached['allowed'],
                denied: (array) $cached['denied'],
                denyReasons: (array) $cached['deny_reasons'],
                precedence: (array) $cached['precedence'],
                source: (string) $cached['source'],
                catalogueVersion: (int) $cached['catalogue_version'],
            );
        }

        $decision = $this->compute($user, $businessId);
        // A cached decision must never outlive a live deny or carve-out that later self-expires:
        // when an expiring waiver lapses the deny returns without any version bump, so a purely
        // version-keyed cache would keep serving the expired waiver's allow — restoring authority
        // the policy just revoked. Cap the TTL at the soonest future expiry so the entry is gone
        // by the time any time-bound row lapses.
        $ttl = min((int) config('authorization.decision_cache_ttl', 300), $this->soonestExpiry($user, $businessId));
        if ($ttl <= 0) {
            return $decision;
        }
        Cache::put($key, $decision->toArray(), $ttl);

        return $decision;
    }

    /**
     * Seconds until the nearest time-bound deny or carve-out for this user/business lapses, or
     * the full configured TTL when nothing is time-bound. Never longer than the configured TTL.
     */
    private function soonestExpiry(string $user, string $businessId): int
    {
        $configured = (int) config('authorization.decision_cache_ttl', 300);
        $denies = DB::table('entity_permission_denies')
            ->where('business_id', $businessId)->where('user_id', $user)
            ->whereNotNull('expires_at')->where('expires_at', '>', now())
            ->orderBy('expires_at')->value('expires_at');
        $carveouts = DB::table('entity_deny_exceptions')
            ->join('entity_permission_denies', 'entity_permission_denies.id', '=', 'entity_deny_exceptions.deny_id')
            ->where('entity_deny_exceptions.business_id', $businessId)
            ->where('entity_permission_denies.user_id', $user)
            ->where('entity_deny_exceptions.active', true)
            ->whereNotNull('entity_deny_exceptions.expires_at')
            ->where('entity_deny_exceptions.expires_at', '>', now())
            ->orderBy('entity_deny_exceptions.expires_at')
            ->value('entity_deny_exceptions.expires_at');

        $soonest = collect([$denies, $carveouts])->filter()->map(fn ($value): int => (int) now()->diffInSeconds($value))->min();
        if ($soonest === null) {
            return $configured;
        }

        return max(1, min($configured, (int) $soonest));
    }

    private function compute(string $user, string $businessId): AuthorizationDecision
    {
        $business = DB::table('business_identities')
            ->where('id', $businessId)
            ->first(['id', 'kind']);

        // Unknown input denies (§6).
        if ($business === null) {
            return new AuthorizationDecision(
                businessId: $businessId,
                kind: 'Unknown',
                allowed: [],
                denied: [],
                denyReasons: [],
                source: 'unknown_business',
                catalogueVersion: 0,
            );
        }

        $kind = (string) $business->kind;
        $version = (int) (DB::table('permission_definitions')->where('active', true)->max('version') ?? 0);

        $direct = DB::table('customer_permissions')
            ->where('user_id', $user)->where('business_id', $businessId)
            ->distinct()->pluck('action')->all();
        $role = OrganizationBusinessRoles::permissionsFor($user)
            ->where('role.business_id', $businessId)
            ->distinct()->pluck('action.action')->all();

        // Explicit denies win over grants, BUT a governed deny exception (D1 carve-out) waives
        // the deny inside its narrower context — the legacy allows() path honours it, so the
        // evaluator must too or parity data and any later cutover would wrongly revoke the
        // waived authority. An active exception on the deny suppresses that deny here.
        $denies = DB::table('entity_permission_denies')
            ->where('business_id', $businessId)
            ->where('user_id', $user)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNotExists(fn ($exception) => $exception->selectRaw('1')->from('entity_deny_exceptions as carve_out')
                ->whereColumn('carve_out.deny_id', 'entity_permission_denies.id')
                ->where('carve_out.active', true)
                ->where(fn ($expiry) => $expiry->whereNull('carve_out.expires_at')
                    ->orWhere('carve_out.expires_at', '>', now())))
            ->get(['action', 'reason']);

        $denyReasons = [];
        foreach ($denies as $deny) {
            $denyReasons[(string) $deny->action] = (string) $deny->reason;
        }

        $precedence = $this->precedence($kind, $direct, $role, $denies);

        return new AuthorizationDecision(
            businessId: $businessId,
            kind: $kind,
            allowed: $precedence['allowed'],
            denied: $precedence['denied'],
            denyReasons: $denyReasons,
            precedence: $precedence['tiers'],
            source: 'central_evaluator',
            catalogueVersion: $version,
        );
    }

    /**
     * The §6 precedence chain, made visible per action (plan §89: effective-access explanation
     * and override-source display). Positive grants are unioned, intersected with the
     * applicable (stable-core bound) catalogue, then explicit prohibitions win. Each action ends
     * in one tier and records WHY — which source granted it, or which rule bound it — so a caller
     * can display the override source instead of only an allow/deny bit.
     *
     * @return array{allowed: list<string>, denied: list<string>, tiers: array<string, array<string, string|null>>}
     */
    private function precedence(string $kind, array $direct, array $role, object $denies): array
    {
        $applicable = PermissionCatalogue::actions($kind);
        $denied = array_map(fn ($d): string => (string) $d->action, $denies->all());
        $reasons = [];
        foreach ($denies as $deny) {
            $reasons[(string) $deny->action] = (string) $deny->reason;
        }

        $allowed = [];
        $tiers = [];
        foreach ($applicable as $action) {
            $tier = $this->tier($action, $direct, $role, $denied);
            $tiers[$action] = ['tier' => $tier, 'reason' => $reasons[$action] ?? null];
            if ($tier === 'allowed') {
                $allowed[] = $action;
            }
        }

        // Prohibitions only apply to actions that reach this evaluator for this entity; an explicit
        // deny on a non-granted action is still surfaced as denied evidence (not allowed).
        $deniedOut = array_values(array_filter($denied, fn (string $a): bool => in_array($a, $applicable, true) || isset($reasons[$a])));

        return ['allowed' => $allowed, 'denied' => $deniedOut, 'tiers' => $tiers];
    }

    /** Which §6 tier an applicable action resolves to, plus the grant source that produced it. */
    private function tier(string $action, array $direct, array $role, array $denied): string
    {
        if (in_array($action, $denied, true)) {
            return 'prohibited';
        }
        if (in_array($action, $direct, true)) {
            return 'allowed';
        }
        if (in_array($action, $role, true)) {
            return 'allowed';
        }

        return 'bound'; // applicable but not granted: absence of a grant is a default denial
    }

    /**
     * Version material for a cache key (plan §8): the catalogue version plus this business's
     * authorization version (bumped transactionally on role/scope/policy change), so a cached
     * decision can be keyed to invalidate when any input version changes. No caching is done
     * here — consumers must recheck authoritative state on sensitive mutations.
     */
    public function versionKey(string $businessId): string
    {
        $catalogue = (int) (DB::table('permission_definitions')->where('active', true)->max('version') ?? 0);
        $entity = (int) (DB::table('entity_authorization_versions')->where('business_id', $businessId)->value('version') ?? 0);

        return "catalogue={$catalogue};entity={$entity}";
    }
}
