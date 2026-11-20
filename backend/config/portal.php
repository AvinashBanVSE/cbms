<?php

declare(strict_types=1);

return [
    'legal_verifier_url' => env('CBM_LEGAL_VERIFIER_URL', ''),
    'legal_verifier_token' => env('CBM_LEGAL_VERIFIER_TOKEN', ''),
    'mapping_code_minutes' => max(1, (int) env('CBM_MAPPING_CODE_MINUTES', 15)),
    'password_history_count' => (int) env('CBM_PASSWORD_HISTORY_COUNT', 5),
    'setup_digest' => env('CBM_SETUP_CODE_SHA256', ''),
];
