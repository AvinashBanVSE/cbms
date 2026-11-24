<?php

declare(strict_types=1);

namespace App\Authorization;

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
     */
    public function decide(string $user, string $businessId): AuthorizationDecision
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

        $grants = CustomerPermissionGrants::forUser($user)
            ->where('business_id', $businessId)
            ->distinct()
            ->pluck('action')
            ->all();

        // Only actions the catalogue marks applicable to this kind count as positive grants.
        $applicable = PermissionCatalogue::actions($kind);
        $allowed = array_values(array_intersect($grants, $applicable));

        // Explicit denies win over grants; a deny on a non-granted action is still surfaced.
        $denies = DB::table('entity_permission_denies')
            ->where('business_id', $businessId)
            ->where('user_id', $user)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get(['action', 'reason']);

        $denied = [];
        $denyReasons = [];
        foreach ($denies as $deny) {
            $action = (string) $deny->action;
            $denied[] = $action;
            $denyReasons[$action] = (string) $deny->reason;
        }

        // A denied action is removed from allowed (prohibitions win, §6).
        $allowed = array_values(array_diff($allowed, $denied));

        return new AuthorizationDecision(
            businessId: $businessId,
            kind: $kind,
            allowed: $allowed,
            denied: $denied,
            denyReasons: $denyReasons,
            source: 'central_evaluator',
            catalogueVersion: $version,
        );
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
