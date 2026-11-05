<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    protected $fillable = ['uuid', 'batch_uuid', 'device_id', 'user_id', 'action', 'parameters', 'status', 'result_message', 'dispatched_at', 'finished_at', 'expires_at'];
    protected function casts(): array { return ['parameters' => 'array', 'dispatched_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
