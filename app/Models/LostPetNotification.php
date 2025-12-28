<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostPetNotification extends Model
{
    protected $fillable = [
        'lost_pet_alert_id',
        'notified_user_id',
        'channel',
        'sent_at',
        'opened_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(LostPetAlert::class, 'lost_pet_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_user_id');
    }

    public function markAsOpened(): void
    {
        if (!$this->opened_at) {
            $this->update(['opened_at' => now()]);
        }
    }
}

