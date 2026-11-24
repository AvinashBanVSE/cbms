<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\Log;

/**
 * Shadow comparison (plan §6 controlled cutover, part 1 of 2).
 *
 * Compares the central evaluator's decision against the legacy enforcement class for a bounded set
 * of actions and logs the parity result. It is deliberately READ-ONLY: it never enforces the
 * evaluator and never changes a controller's outcome, so it is safe to run while the evaluator is
 * still shadow-only. Divergences it surfaces are the evidence the team reviews before enabling the
 * `evaluator_enforced` gate — that cutover itself is a separate, config-gated decision not made here.
 */
final class ShadowComparison
{
    public function __construct(private readonly EntityRoleAccess $access) {}

    /**
     * Compare the evaluator against the legacy check for the given actions. Returns the per-action
     * parity result so a caller or test can inspect it directly; it is also logged under a stable
     * channel with a single log line per business/user/action.
     *
     * @param  list<string>  $actions
     * @return array{business_id: string, user_id: string, catalogue_version: string, matches: int, divergences: int, results: array<string, array{evaluator: bool, legacy: bool, match: bool}>}
     */
    public function compare(string $user, string $business, array $actions): array
    {
        $decision = app(AuthorizationEvaluator::class)->decide($user, $business);
        $results = [];
        $matches = 0;
        $divergences = 0;

        foreach ($actions as $action) {
            $evaluator = in_array($action, $decision->allowed, true);
            $legacy = $this->access->allows($user, $business, $action);
            $match = $evaluator === $legacy;
            $match ? $matches++ : $divergences++;
            $results[$action] = ['evaluator' => $evaluator, 'legacy' => $legacy, 'match' => $match];
        }

        Log::channel('shadow')->info('authorization.shadow_comparison', [
            'business_id' => $business,
            'user_id' => $user,
            'catalogue_version' => $decision->catalogueVersion,
            'version_key' => app(AuthorizationEvaluator::class)->versionKey($business),
            'matches' => $matches,
            'divergences' => $divergences,
            'results' => $results,
        ]);

        return [
            'business_id' => $business,
            'user_id' => $user,
            'catalogue_version' => $decision->catalogueVersion,
            'matches' => $matches,
            'divergences' => $divergences,
            'results' => $results,
        ];
    }
}
