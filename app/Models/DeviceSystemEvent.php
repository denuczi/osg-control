<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceSystemEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'event_uuid', 'channel', 'record_id', 'event_id', 'provider', 'level', 'observed_at', 'received_at', 'message', 'user_sid'];

    protected function casts(): array
    {
        return ['record_id' => 'integer', 'event_id' => 'integer', 'level' => 'integer',
            'observed_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
