<?php

declare(strict_types=1);

return [
    'mapping_code_minutes' => max(1, (int) env('CBM_MAPPING_CODE_MINUTES', 15)),
    'password_history_count' => (int) env('CBM_PASSWORD_HISTORY_COUNT', 5),
    'setup_digest' => env('CBM_SETUP_CODE_SHA256', ''),
];
