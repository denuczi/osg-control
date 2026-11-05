<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;

/** Shared online definition for queries, summaries and command eligibility. */
final class DeviceAvailability
{
    public function __construct(private readonly MonitorSettings $settings) {}

    public function cutoff(): \Illuminate\Support\Carbon
    {
        $heartbeat = $this->settings->all()['heartbeat_interval_seconds'];
        return now()->subSeconds(max((int) config('nodo.stale_seconds', 75), $heartbeat * 3 + 15));
    }

    public function online(Builder $query, \Illuminate\Support\Carbon $cutoff): Builder
    {
        return $query->whereNull('revoked_at')->where('is_demo', false)
            ->whereNotNull('last_seen_at')->where('last_seen_at', '>', $cutoff);
    }

    public function isOnline(Device $device, \Illuminate\Support\Carbon $cutoff): bool
    {
        if ($device->revoked_at) {
            return false;
        }
        if ($device->is_demo) {
            return false;
        }
        return $device->last_seen_at?->greaterThan($cutoff) === true;
    }
}
