<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricSample extends Model
{
    public $timestamps = false;
    protected $fillable = ['device_id', 'cpu_percent', 'memory_percent', 'disk_percent', 'network_rx_bps', 'network_tx_bps', 'recorded_at', 'sample_uuid', 'received_at'];
    protected function casts(): array { return ['recorded_at' => 'datetime', 'received_at' => 'datetime', 'cpu_percent' => 'float', 'memory_percent' => 'float', 'disk_percent' => 'float', 'network_rx_bps' => 'integer', 'network_tx_bps' => 'integer']; }
}
