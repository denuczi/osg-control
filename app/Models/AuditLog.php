<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'device_id', 'event', 'context'];
    protected function casts(): array { return ['context' => 'array']; }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
