<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    protected $fillable = [
        'user_id',
        'pet_id',
        'type',
        'title',
        'description',
        'due_date',
        'reminder_date',
        'status',
        'sent_at',
        'snoozed_until',
        'metadata',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reminder_date' => 'date',
        'sent_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function snooze(int $days): void
    {
        $this->update([
            'status' => 'snoozed',
            'snoozed_until' => now()->addDays($days),
        ]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }

    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }
}

