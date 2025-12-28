<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'participant_one_id',
        'participant_two_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_one_id');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function getOtherParticipant(int $userId): ?User
    {
        if ($this->participant_one_id === $userId) {
            return $this->participantTwo;
        }

        if ($this->participant_two_id === $userId) {
            return $this->participantOne;
        }

        return null;
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->participant_one_id === $userId || $this->participant_two_id === $userId;
    }
}

