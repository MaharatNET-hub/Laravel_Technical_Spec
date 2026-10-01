<?php

return [
    // true = client details are always hidden and the switch is removed (safe for a public link)
    'lock_disclosure' => (bool) env('DEMO_LOCK_DISCLOSURE', false),

    // client details hidden by default on a fresh install (the switch can still change it unless locked)
    'hide_default' => (bool) env('DEMO_HIDE_DEFAULT', true),

    // private direct-download link to the sample submittal, fetched by the seeder when the file is missing
    'sample_url' => env('DEMO_SAMPLE_URL', ''),

    // seconds of work per request while reading pages (keep under the host's max_execution_time)
    'step_seconds' => (float) env('DEMO_STEP_SECONDS', 12),
];
