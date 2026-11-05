<?php

namespace App\Services;

use App\Models\SystemSetting;

/** Intervals are server-authoritative. No agent can decrease them. */
class MonitorSettings
{
    public const DEFAULTS = [
        'metrics_interval_seconds' => 60,
        'dashboard_refresh_seconds' => 60,
        'heartbeat_interval_seconds' => 30,
        'command_poll_seconds' => 10,
        'retention_days' => 7,
    ];

    public const RANGES = [
        'metrics_interval_seconds' => [5, 3600],
        'dashboard_refresh_seconds' => [5, 300],
        'heartbeat_interval_seconds' => [20, 300],
        'command_poll_seconds' => [5, 60],
        'retention_days' => [1, 90],
    ];

    public function all(): array
    {
        $stored = SystemSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();
        $values = array_replace(self::DEFAULTS, array_map('intval', $stored));
        foreach (self::RANGES as $key => [$low, $high]) {
            $values[$key] = max($low, min($high, $values[$key]));
        }
        return $values;
    }

    public function agent(): array
    {
        $values = $this->all();
        return [
            'metrics_interval_seconds' => $values['metrics_interval_seconds'],
            'heartbeat_interval_seconds' => $values['heartbeat_interval_seconds'],
            'command_poll_seconds' => $values['command_poll_seconds'],
        ];
    }

    public function dashboardRefresh(): int
    {
        return $this->all()['dashboard_refresh_seconds'];
    }

    public function retentionDays(): int
    {
        return $this->all()['retention_days'];
    }
}
