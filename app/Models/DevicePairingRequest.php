<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevicePairingRequest extends Model
{
    protected $fillable = ['uuid', 'device_uuid', 'hostname', 'platform', 'source_ip', 'token_hash',
        'verification_code_encrypted', 'status', 'failed_attempts', 'approved_by', 'expires_at', 'resolved_at'];

    protected $hidden = ['token_hash', 'verification_code_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'resolved_at' => 'datetime', 'failed_attempts' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
