<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceDiagnostic extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'hardware', 'disks', 'security', 'captured_at', 'received_at'];

    protected function casts(): array
    {
        return ['hardware' => 'array', 'disks' => 'array', 'security' => 'array',
            'captured_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
