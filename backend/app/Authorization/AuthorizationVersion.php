<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;

/**
 * Single authority-version bumb point (plan §8 caching precondition).
 *
 * A cached evaluator decision is keyed on AuthorizationEvaluator::versionKey(), which reads
 * entity_authorization_versions. So EVERY mutation that can change an authorization decision — role
 * edits, grants, denies, deny-exceptions, scope selectors — must bump this business's version before
 * the change commits, otherwise a cached decision can outlive a revoke (the plan's rule that
 * rollback must never restore revoked authority). Role mutations already bump via
 * EntityRoles::changed(); this centralizes the bump for the other governed writers so none can
 * silently skip it.
 */
final class AuthorizationVersion
{
    public static function bump(string $business): void
    {
        DB::table('entity_authorization_versions')->insertOrIgnore(['business_id' => $business, 'version' => 0]);
        DB::table('entity_authorization_versions')->where('business_id', $business)->increment('version');
    }
}
