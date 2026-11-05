<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable membership of an administrative fan-out operation. */
final class BulkCommandBatch extends Model
{
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['uuid', 'user_id', 'action', 'request_hash', 'target_uuids'];

    protected function casts(): array
    {
        return ['target_uuids' => 'array'];
    }
}
