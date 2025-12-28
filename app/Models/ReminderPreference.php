<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderPreference extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'days_before',
        'email_enabled',
        'push_enabled',
        'whatsapp_enabled',
    ];

    protected $casts = [
        'days_before' => 'integer',
        'email_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

