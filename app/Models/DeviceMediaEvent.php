<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceMediaEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['device_id', 'event_uuid', 'kind', 'volume', 'observed_at', 'received_at'];
    protected function casts(): array
    {
        return ['volume' => 'array', 'observed_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
