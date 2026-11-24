<?php

declare(strict_types=1);

return [
    // When false (default) the central evaluator is advisory/shadow only: the existing access
    // classes keep enforcing and nothing changes. Cutover is config-gated and reversible.
    // Cutover gate (plan §6 step 6), default OFF. Both must be set for the evaluator to be
    // authoritative: the flag AND the business being listed here, so a bounded context is cut
    // over one at a time. With either off, the existing enforcement classes answer unchanged.
    'evaluator_enforced' => (bool) env('CBM_AUTHORIZATION_EVALUATOR_ENFORCED', false),
    'enforced_businesses' => array_values(array_filter(explode(',', (string) env('CBM_AUTHORIZATION_ENFORCED_BUSINESSES', '')))),

    // Caching (plan §8), default-off. When true, evaluator decisions are cached keyed on
    // versionKey(); every authorization mutation bumps that version, so a cached decision is
    // invalidated before its next read and can never outlive a revoke. Independent of
    // evaluator_enforced — caching only changes how a decision is obtained, never what it means.
    'evaluator_decisions_cached' => (bool) env('CBM_AUTHORIZATION_DECISIONS_CACHED', false),
    'decision_cache_ttl' => (int) env('CBM_AUTHORIZATION_DECISION_CACHE_TTL', 300),
];
