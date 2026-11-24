<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;

/**
 * Owner-linked, purpose-limited cross-entity authority (Master Decisions §7.5/§7.6, plan D2/D1).
 *
 * A Distributor's authority scope may reach a mapped Shop ONLY when that Shop shares an Owner ID
 * with the Distributor through an Ownership Link AND a Main Portal-governed rule expressly permits
 * that owner-linked scope. A Distributor–Shop Mapping alone NEVER expands the Distributor's scope
 * to a Shop belonging to another Organization; mapping enables only the expressly authorized
 * relationship interactions. Ownership links are never inferred from name, contact, account or
 * informal association, and the Shop's independent identity and data boundary remain intact.
 *
 * This evaluator is additive: it may only EXTEND a Distributor actor's reach to a shared-owner
 * Shop, and only for a permitted purpose. It can never reduce or bypass the base eligibility model.
 */
final class OwnerLinkedScope
{
    /**
     * Whether actor (a Distributor-context principal) may reach targetEntity for purpose, via a
     * shared Owner ID. Returns true only when every §7.5 condition holds:
     *   - actor's own business is a Distributor and the actor holds the action there;
     *   - target is a Shop mapped to that Distributor's distribution context;
     *   - target shares an Owner ID with the Distributor through an Ownership Link;
     *   - a Main Portal-governed rule expressly permits purpose for this owner-linked pair.
     */
    public function allows(string $actor, string $targetEntity, string $action): bool
    {
        $actorBusiness = $this->distributorBusiness($actor);
        if ($actorBusiness === null) {
            return false;
        }

        $target = DB::table('business_identities')->where('id', $targetEntity)->first(['id', 'kind', 'parent_id']);
        if ($target === null || $target->kind !== 'Shop') {
            return false;
        }

        // Mapping alone is never sufficient: the target must be mapped into the Distributor's
        // distribution context AND share an Owner ID with the Distributor (§7.5).
        if (! $this->mappedToDistributor($targetEntity, $actorBusiness)) {
            return false;
        }

        if (! $this->sharesOwner($actorBusiness, $targetEntity)) {
            return false;
        }

        // A Main Portal-governed rule must expressly permit this owner-linked purpose.
        return $this->governedRulePermits($targetEntity, $action);
    }

    /** The Distributor business id the actor holds a governed role on, or null. */
    private function distributorBusiness(string $actor): ?string
    {
        return DB::table('organization_role_assignments as assignment')
            ->join('organization_business_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('business_identities as business', 'business.id', '=', 'role.business_id')
            ->where('assignment.user_id', $actor)
            ->where('business.kind', 'Distributor')
            ->where('role.governed', true)
            ->where('role.archived', false)
            ->value('business.id');
    }

    private function mappedToDistributor(string $shop, string $distributor): bool
    {
        // Only an Active mapping counts; pending/withdrawn/expired/terminated never extends scope.
        return DB::table('distributor_shop_mappings')
            ->where(['distributor_id' => $distributor, 'shop_id' => $shop, 'state' => 'Active'])
            ->exists();
    }

    /** True only when the Shop and Distributor share at least one Owner ID via Ownership Links. */
    private function sharesOwner(string $distributor, string $shop): bool
    {
        return DB::table('ownership_links as shop_owner')
            ->join('ownership_links as distributor_owner', 'distributor_owner.owner_id', '=', 'shop_owner.owner_id')
            ->where('shop_owner.business_id', $shop)
            ->where('distributor_owner.business_id', $distributor)
            ->exists();
    }

    /**
     * The Main Portal-governed rule set for owner-linked scope. A Main Portal administrator enables
     * the owner-linked purpose per target entity kind in `owner_linked_scope_rules`; an enabled,
     * active row is the "expressly authorized" rule §7.5 requires. Absence means owner-linked
     * cross-entity authority is disabled — the safe default.
     */
    private function governedRulePermits(string $targetEntity, string $action): bool
    {
        $kind = DB::table('business_identities')->where('id', $targetEntity)->value('kind');

        return $kind !== null && DB::table('owner_linked_scope_rules')
            ->where(['action' => $action, 'target_kind' => $kind, 'enabled' => true])
            ->exists();
    }
}
