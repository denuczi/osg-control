<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceReport extends Model
{
    protected $fillable = ['uuid', 'device_id', 'user_id', 'status', 'ai_requested', 'ai_status',
        'ai_provider', 'ai_error_code', 'error_code', 'file_path', 'period_from', 'period_to', 'finished_at'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['ai_requested' => 'boolean', 'period_from' => 'datetime',
            'period_to' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
