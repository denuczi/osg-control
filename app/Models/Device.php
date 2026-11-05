<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $fillable = ['uuid', 'name', 'hostname', 'platform', 'token_hash', 'last_seen_at', 'last_sampled_at', 'processes_sampled_at', 'processes_total_count', 'cpu_percent', 'memory_percent', 'disk_percent', 'memory_total_bytes', 'memory_used_bytes', 'memory_available_bytes', 'disk_total_bytes', 'disk_used_bytes', 'disk_free_bytes', 'network_rx_bps', 'network_tx_bps', 'processes', 'revoked_at', 'archived_at', 'is_demo'];
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_sampled_at' => 'datetime', 'processes_sampled_at' => 'datetime', 'revoked_at' => 'datetime', 'archived_at' => 'datetime', 'processes' => 'array', 'processes_total_count' => 'integer', 'cpu_percent' => 'float', 'memory_percent' => 'float', 'disk_percent' => 'float', 'memory_total_bytes' => 'integer', 'memory_used_bytes' => 'integer', 'memory_available_bytes' => 'integer', 'disk_total_bytes' => 'integer', 'disk_used_bytes' => 'integer', 'disk_free_bytes' => 'integer', 'network_rx_bps' => 'integer', 'network_tx_bps' => 'integer', 'is_demo' => 'boolean'];
    }
    public function diagnostic(): HasOne { return $this->hasOne(DeviceDiagnostic::class); }
    public function systemEvents(): HasMany { return $this->hasMany(DeviceSystemEvent::class); }
    public function mediaEvents(): HasMany { return $this->hasMany(DeviceMediaEvent::class); }
    public function inventory(): HasOne { return $this->hasOne(DeviceInventory::class); }
    public function metrics(): HasMany { return $this->hasMany(MetricSample::class); }
    public function commands(): HasMany { return $this->hasMany(DeviceCommand::class); }
    public function isOnline(?\Illuminate\Support\Carbon $cutoff = null): bool
    {
        $availability = app(\App\Services\DeviceAvailability::class);
        return $availability->isOnline($this, $cutoff ?? $availability->cutoff());
    }
    public function getRouteKeyName(): string { return 'uuid'; }
}
