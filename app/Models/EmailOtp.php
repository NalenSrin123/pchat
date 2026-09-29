<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailOtp extends Model
{
    protected $fillable = ['email', 'otp', 'expires_at'];
    protected $hidden = ['otp'];
    protected function casts(): array { return ['expires_at' => 'datetime']; }
}
