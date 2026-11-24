<?php

declare(strict_types=1);

return [
    // When false (default) the central evaluator is advisory/shadow only: the existing access
    // classes keep enforcing and nothing changes. Cutover is config-gated and reversible.
    'evaluator_enforced' => (bool) env('CBM_AUTHORIZATION_EVALUATOR_ENFORCED', false),
];
