<?php

return [
    // true = client details are always hidden and the switch is removed (safe for a public link)
    'lock_disclosure' => (bool) env('DEMO_LOCK_DISCLOSURE', false),

    // seconds of work per request while reading pages (keep under the host's max_execution_time)
    'step_seconds' => (float) env('DEMO_STEP_SECONDS', 12),
];
