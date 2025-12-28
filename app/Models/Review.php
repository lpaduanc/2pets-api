<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    protected $fillable = [
        'professional_id',
        'client_id',
        'appointment_id',
        'rating',
        'comment',
        'is_verified',
        'is_visible',
        'is_flagged',
        'flag_reason',
        'helpful_count',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified' => 'boolean',
        'is_visible' => 'boolean',
        'is_flagged' => 'boolean',
        'helpful_count' => 'integer',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(ReviewResponse::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class);
    }

    public function helpfulVotes(): HasMany
    {
        return $this->hasMany(ReviewHelpfulVote::class);
    }

    public function hasResponse(): bool
    {
        return $this->response()->exists();
    }

    public function isVerified(): bool
    {
        return $this->is_verified;
    }
}

