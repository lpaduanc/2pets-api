<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationThrottle extends Model
{
    protected $fillable = [
        'email',
        'attempts',
        'last_attempt_at',
        'reset_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'reset_at' => 'datetime',
    ];
}
