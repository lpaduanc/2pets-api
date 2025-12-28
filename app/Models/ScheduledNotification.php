<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'data',
        'scheduled_for',
        'sent_at',
        'sent',
    ];

    protected $casts = [
        'data' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'sent' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

