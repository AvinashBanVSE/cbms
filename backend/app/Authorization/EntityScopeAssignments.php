<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D2 governed scope selectors.
 *
 * A selector records how a user's entity scope is established. Resolution always re-evaluates
 * the live eligibility model (association, lifecycle, tenant boundary) — a selector adds target
 * entities within what the relationship model already permits; it never manufactures an
 * association, grants an operation, or widens the tenant boundary. Exclusions always subtract.
 * The dynamic (future-eligible) selector is separately governed and only resolves when expressly
 * active for the assignment.
 */
final class EntityScopeAssignments
{
    public const SELF = 'self';

    public const SELECTED = 'selected';

    public const SNAPSHOT = 'snapshot';

    public const DYNAMIC = 'dynamic';

    public const SELECTORS = [self::SELF, self::SELECTED, self::SNAPSHOT, self::DYNAMIC];

    /**
     * Resolve the target entities this selector yields for the current access. Returns the
     * eligible entities the selector selects minus exclusions. An absent selector means the
     * legacy implicit self-scope still applies (existing behavior is unchanged).
     *
     * @return list<string> business ids
     */
    public function resolve(string $user, string $business): array
    {
        $assignment = DB::table('entity_scope_assignments')
            ->where(['user_id' => $user, 'business_id' => $business, 'active' => true])
            ->orderByDesc('version')
            ->first();

        if ($assignment === null) {
            return [$business]; // legacy implicit self-scope, unchanged
        }

        $exclusions = array_values((array) json_decode((string) ($assignment->exclusions ?? '[]'), true));
        $access = new EntityRoleAccess;

        $targets = match ($assignment->selector) {
            self::SELF => [$business],
            self::SELECTED => array_values(array_unique([
                $business,
                ...array_filter((array) json_decode((string) ($assignment->entities ?? '[]'), true)),
            ])),
            self::SNAPSHOT => $this->currentlyEligible($access, $user, $business),
            self::DYNAMIC => $assignment->dynamic
                ? $this->dynamicEligible($access, $user, $business, $assignment)
                : [$business],
            default => [$business],
        };

        return array_values(array_diff($targets, $exclusions));
    }

    /**
     * Governed change to a selector. Any addition of targets (new entities, removal of
     * exclusions, snapshot -> dynamic) is an access expansion and is refused here unless the
     * caller proves the actor holds current, independent authority for the widening operation.
     */
    public function save(
        string $actor,
        string $business,
        string $user,
        array $data,
        callable $proveAuthority,
    ): array {
        return DB::transaction(function () use ($actor, $business, $user, $data, $proveAuthority): array {
            $current = DB::table('entity_scope_assignments')
                ->where(['business_id' => $business, 'user_id' => $user])
                ->lockForUpdate()->first();

            $selector = $data['selector'];
            $dynamic = (bool) ($data['dynamic'] ?? false);

            // Only an expressly granted authority may enable future-eligible (dynamic) scope.
            abort_if($dynamic && ! $proveAuthority('authorization.scope.dynamic'), 403);

            $next = [
                'selector' => $selector,
                'entities' => array_values(array_unique($data['entities'] ?? [])),
                'exclusions' => array_values(array_unique($data['exclusions'] ?? [])),
                'predicates' => $data['predicates'] ?? null,
                'dynamic' => $dynamic,
                'anchor_id' => $data['anchor_id'] ?? null,
            ];

            // Optimistic concurrency on the selector record.
            $expected = $data['expected_version'] ?? null;
            if ($current !== null && (int) $current->version !== (int) $expected) {
                abort(409, 'The scope assignment changed. Reload and retry.');
            }

            // Access-expansion guard: widening beyond what the current record selected is refused.
            if ($current !== null && $this->widens($current, $next)) {
                abort_unless($proveAuthority('authorization.scope.expand'), 403, 'Widening scope needs expansion authority.');
            }

            if ($current === null) {
                $id = (string) Str::uuid7();
                DB::table('entity_scope_assignments')->insert([
                    'id' => $id, 'business_id' => $business, 'user_id' => $user, 'granted_by' => $actor,
                    'selector' => $selector, 'anchor_id' => $next['anchor_id'],
                    'entities' => json_encode($next['entities']), 'exclusions' => json_encode($next['exclusions']),
                    'predicates' => $next['predicates'] !== null ? json_encode($next['predicates']) : null,
                    'dynamic' => $dynamic, 'active' => true, 'version' => 1,
                ]);

                return ['id' => $id, 'version' => 1];
            }

            DB::table('entity_scope_assignments')->where('id', $current->id)->update([
                'selector' => $selector, 'anchor_id' => $next['anchor_id'],
                'entities' => json_encode($next['entities']), 'exclusions' => json_encode($next['exclusions']),
                'predicates' => $next['predicates'] !== null ? json_encode($next['predicates']) : null,
                'dynamic' => $dynamic, 'granted_by' => $actor,
                'version' => (int) $current->version + 1,
            ]);

            return ['id' => $current->id, 'version' => (int) $current->version + 1];
        });
    }

    /** All currently eligible targets reachable from the anchor, per the live model. */
    private function currentlyEligible(EntityRoleAccess $access, string $user, string $business): array
    {
        $targets = [$business];
        $entity = DB::table('business_identities')->where('id', $business)->first();
        if ($entity === null) {
            return $targets;
        }

        $organization = match ($entity->kind) {
            'Organization' => $business,
            'Distributor', 'Shop' => DB::table('business_identities')->where('id', $business)->value('parent_id'),
            default => null,
        };

        if ($organization === null) {
            return $targets;
        }

        foreach (DB::table('business_identities')
            ->where('parent_id', $organization)
            ->whereIn('kind', ['Distributor', 'Shop'])
            ->where('operational_state', 'Active')
            ->pluck('id') as $child) {
            if ($access->eligible($user, $child)) {
                $targets[] = $child;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Future-eligible: re-resolve eligibility for the anchored set on every access. A fixed anchor
     * and supported predicates only — never arbitrary SQL or unconstrained filters.
     */
    private function dynamicEligible(EntityRoleAccess $access, string $user, string $business, object $assignment): array
    {
        $anchor = (string) ($assignment->anchor_id ?? $business);
        $predicates = (array) json_decode((string) ($assignment->predicates ?? '[]'), true);
        $kinds = array_values(array_intersect(
            (array) ($predicates['kinds'] ?? []),
            ['Organization', 'Distributor', 'Shop'],
        ));

        $targets = $this->currentlyEligible($access, $user, $anchor);

        if ($kinds !== []) {
            foreach (DB::table('business_identities')
                ->where('parent_id', $anchor)
                ->whereIn('kind', $kinds)
                ->where('operational_state', 'Active')
                ->pluck('id') as $future) {
                if ($access->eligible($user, $future)) {
                    $targets[] = $future;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /** Does $next select more than $current? (new entities, dropped exclusions, dynamic on) */
    private function widens(object $current, array $next): bool
    {
        $currentEntities = array_values((array) json_decode((string) ($current->entities ?? '[]'), true));
        $currentExclusions = array_values((array) json_decode((string) ($current->exclusions ?? '[]'), true));

        if ($next['dynamic'] && ! $current->dynamic) {
            return true;
        }

        if ($current->selector === self::SNAPSHOT && $next['selector'] === self::DYNAMIC) {
            return true;
        }

        if (array_diff($next['entities'], $currentEntities) !== []) {
            return true;
        }

        return array_diff($currentExclusions, $next['exclusions']) !== [];
    }
}
