<?php

namespace App\Support;

use App\Models\Device;

/** Public, explicit UI projection: never serialize credentials or whole models. */
final class DeviceViewData
{
    public static function from(Device $device, bool $includeProcesses = false, ?\Illuminate\Support\Carbon $cutoff = null): array
    {
        $online = $device->isOnline($cutoff);
        $data = [
            'uuid' => $device->uuid,
            'name' => $device->name,
            'hostname' => $device->hostname,
            'platform' => $device->platform,
            'demo' => (bool) $device->is_demo,
            'online' => $online,
            'cpu' => $online ? $device->cpu_percent : null,
            'memory' => $online ? $device->memory_percent : null,
            'disk' => $online ? $device->disk_percent : null,
            'memory_total_bytes' => $device->memory_total_bytes,
            'memory_used_bytes' => $device->memory_used_bytes,
            'memory_available_bytes' => $device->memory_available_bytes,
            'disk_total_bytes' => $device->disk_total_bytes,
            'disk_used_bytes' => $device->disk_used_bytes,
            'disk_free_bytes' => $device->disk_free_bytes,
            'network_rx_bps' => $device->network_rx_bps,
            'network_tx_bps' => $device->network_tx_bps,
            'last_seen' => $device->last_seen_at?->toIso8601String(),
            'last_sampled' => $device->last_sampled_at?->toIso8601String(),
            'processes_sampled_at' => $device->processes_sampled_at?->toIso8601String(),
            'processes_total_count' => self::recentProcessTotal($device, $online),
        ];
        if ($includeProcesses) {
            $data['inventory'] = $device->inventory ? [
                'processor' => $device->inventory->processor,
                'volumes' => $device->inventory->volumes,
                'network' => $device->inventory->network,
                'captured_at' => $device->inventory->captured_at?->toIso8601String(),
            ] : null;
            $data['processes'] = self::recentProcesses($device, $online);
        }
        return $data;
    }

    public static function recentProcessTotal(Device $device, bool $online): ?int
    {
        if (! $online) {
            return null;
        }
        $captured = $device->processes_sampled_at ?? $device->last_sampled_at ?? $device->last_seen_at;
        if ($captured === null || $captured->lt(now()->subMinutes(5))) {
            return null;
        }
        return $device->processes_total_count;
    }

    public static function recentProcesses(Device $device, bool $online): array
    {
        if (! $online) {
            return [];
        }
        // During migration older agents have no process-specific timestamp.
        $captured = $device->processes_sampled_at ?? $device->last_sampled_at ?? $device->last_seen_at;
        if ($captured === null || $captured->lt(now()->subMinutes(5))) {
            return [];
        }
        return array_values($device->processes ?? []);
    }
}
