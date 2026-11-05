<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentCode extends Model
{
    protected $fillable = ['code_hash', 'expires_at', 'used_at'];
    protected $hidden = ['code_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'used_at' => 'datetime']; }
}
