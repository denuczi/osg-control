<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una propuesta nunca equivale a una orden: requiere confirmación explícita. */
final class AiActionProposal extends Model
{
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'uuid', 'user_id', 'batch_uuid', 'action', 'device_uuids',
        'message', 'status', 'expires_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'device_uuids' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
