<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceInventory extends Model
{
    protected $fillable = ['device_id', 'processor', 'volumes', 'network', 'captured_at'];
    protected function casts(): array
    {
        return ['processor' => 'array', 'volumes' => 'array', 'network' => 'array', 'captured_at' => 'datetime'];
    }
}
