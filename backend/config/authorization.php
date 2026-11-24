<?php

declare(strict_types=1);

return [
    // When false (default) the central evaluator is advisory/shadow only: the existing access
    // classes keep enforcing and nothing changes. Cutover is config-gated and reversible.
    'evaluator_enforced' => (bool) env('CBM_AUTHORIZATION_EVALUATOR_ENFORCED', false),

    // Caching (plan §8), default-off. When true, evaluator decisions are cached keyed on
    // versionKey(); every authorization mutation bumps that version, so a cached decision is
    // invalidated before its next read and can never outlive a revoke. Independent of
    // evaluator_enforced — caching only changes how a decision is obtained, never what it means.
    'evaluator_decisions_cached' => (bool) env('CBM_AUTHORIZATION_DECISIONS_CACHED', false),
    'decision_cache_ttl' => (int) env('CBM_AUTHORIZATION_DECISION_CACHE_TTL', 300),
];
