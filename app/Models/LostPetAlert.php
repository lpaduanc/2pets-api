<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LostPetAlert extends Model
{
    protected $fillable = [
        'pet_id',
        'user_id',
        'status',
        'description',
        'last_seen_location',
        'last_seen_latitude',
        'last_seen_longitude',
        'alert_radius_km',
        'last_seen_at',
        'contact_info',
        'photos',
        'microchip_number',
        'reward_amount',
        'found_at',
        'found_details',
        'views_count',
        'shares_count',
    ];

    protected $casts = [
        'last_seen_latitude' => 'decimal:7',
        'last_seen_longitude' => 'decimal:7',
        'alert_radius_km' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'contact_info' => 'array',
        'photos' => 'array',
        'reward_amount' => 'decimal:2',
        'found_at' => 'datetime',
        'views_count' => 'integer',
        'shares_count' => 'integer',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(FoundPetReport::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(LostPetNotification::class);
    }

    public function markAsFound(string $details): void
    {
        $this->update([
            'status' => 'found',
            'found_at' => now(),
            'found_details' => $details,
        ]);

        // Update pet status
        $this->pet->update([
            'is_lost' => false,
            'lost_since' => null,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);

        $this->pet->update([
            'is_lost' => false,
            'lost_since' => null,
        ]);
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementShares(): void
    {
        $this->increment('shares_count');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getDaysLost(): int
    {
        return $this->last_seen_at->diffInDays(now());
    }
}

