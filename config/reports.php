<?php

return [
    'display_timezone' => env('REPORT_TIMEZONE', 'America/Argentina/Cordoba'),
    'weasyprint_binary' => env('NODO_PDF_BINARY', 'weasyprint'),
    'pdf_timeout_seconds' => 35,
    'retention_days' => 30,
    'fleet_max_devices' => (int) env('REPORT_FLEET_MAX_DEVICES', 250),
    'fleet_max_html_bytes' => 8 * 1024 * 1024,
    'max_reports_per_day_per_user' => 20,
    'ai' => [
        'provider' => env('REPORT_AI_PROVIDER', 'groq'),
        'model' => env('REPORT_AI_MODEL', 'openai/gpt-oss-20b'),
        'openai_key' => env('OPENAI_API_KEY'),
        'groq_key' => env('GROQ_API_KEY'),
        'openai_compatible_key' => env('REPORT_AI_COMPATIBLE_KEY'),
        // Allowlisted at deploy time; never accepts a URL supplied by a browser or an agent.
        'compatible_url' => env('REPORT_AI_COMPATIBLE_URL'),
        // Local inference runs on the Debian server (loopback only).
        'local_url' => env('REPORT_AI_LOCAL_URL', 'http://127.0.0.1:11434/v1'),
    ],
];
