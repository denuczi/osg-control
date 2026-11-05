<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FleetReport extends Model
{
    protected $fillable = ['uuid', 'user_id', 'status', 'error_code', 'file_path', 'device_count', 'finished_at', 'run_token', 'processing_started_at'];

    protected $hidden = ['file_path', 'run_token'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime', 'processing_started_at' => 'datetime', 'device_count' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
