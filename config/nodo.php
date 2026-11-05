<?php
return [
    'network_report_timezone' => env('NODO_NETWORK_REPORT_TIMEZONE', 'America/Argentina/Cordoba'),
    'stale_seconds' => (int) env('AGENT_STALE_SECONDS', 75),
    'retention_days' => (int) env('METRICS_RETENTION_DAYS', 7),
    'media_retention_days' => (int) env('NODO_MEDIA_RETENTION_DAYS', 180),
    'system_event_retention_days' => (int) env('NODO_SYSTEM_EVENT_RETENTION_DAYS', 180),
    'system_event_max_events' => (int) env('NODO_SYSTEM_EVENT_MAX_EVENTS', 50000),
    'media_max_events' => (int) env('NODO_MEDIA_MAX_EVENTS', 50000),
    'administrative_retention_days' => (int) env('NODO_ADMIN_RETENTION_DAYS', 180),
    'telemetry_max_rows' => (int) env('NODO_TELEMETRY_MAX_ROWS', 150000),
    'telemetry_max_mib' => (int) env('NODO_TELEMETRY_MAX_MIB', 256),
];
