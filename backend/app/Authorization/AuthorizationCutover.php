<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * Controlled cutover gate (plan §6 step 6).
 *
 * Chooses the authoritative source for one authorization decision: the existing enforcement class
 * (default) or the central evaluator. It is intentionally narrow so the team can cutover one
 * bounded context at a time — a business is only enforced-through-the-evaluator when
 * `evaluator_enforced` is on AND that business is listed in `enforced_businesses`.
 *
 * Cutover semantics when active, per plan step 6:
 * - the evaluator's decision IS the answer (legacy is not OR-combined with it);
 * - unknown inputs already deny inside the evaluator (fail closed);
 * - every authority change bumps entity_authorization_versions, so a versionKey()-keyed cache
 *   cannot outlive a revoke — rollback therefore preserves revocations;
 * - a business not in the bounded set, or with the flag off, falls back to the legacy answer and
 *   its behaviour is byte-for-byte unchanged (this is why default-off is safe to ship).
 *
 * Default OFF. Turning it on is a production rollout decision, not a code change.
 */
final class AuthorizationCutover
{
    public function __construct(private readonly AuthorizationEvaluator $evaluator) {}

    public function enforced(string $business): bool
    {
        $bounded = (array) config('authorization.enforced_businesses', []);

        return config('authorization.evaluator_enforced', false) === true
            && in_array($business, $bounded, true);
    }

    /**
     * The authoritative allow/deny for one action in a bounded context.
     */
    public function allows(string $user, string $business, string $action): bool
    {
        return in_array($action, $this->evaluator->decide($user, $business)->allowed, true);
    }
}
