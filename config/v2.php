<?php

return [
    // the v2 admin account (created on first run). Leave the password empty to get a random one,
    // printed to the log and saved in storage/app/v2/ADMIN-PASSWORD.txt.
    'admin_email' => env('V2_ADMIN_EMAIL', 'admin@example.com'),
    'admin_password' => env('V2_ADMIN_PASSWORD', ''),

    // public site language: ar or en
    'locale' => env('V2_LOCALE', 'ar'),

    // largest submittal accepted from the public form (MB)
    'max_upload_mb' => (int) env('V2_MAX_UPLOAD_MB', 100),

    // the issued PDF is attached to the email up to this size; above it the email carries a download link
    'mail_attach_mb' => (int) env('V2_MAIL_ATTACH_MB', 8),
];
