<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoundPetReport extends Model
{
    protected $fillable = [
        'lost_pet_alert_id',
        'reporter_user_id',
        'reporter_name',
        'reporter_phone',
        'reporter_email',
        'description',
        'location',
        'latitude',
        'longitude',
        'spotted_at',
        'photos',
        'status',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'spotted_at' => 'datetime',
        'photos' => 'array',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(LostPetAlert::class, 'lost_pet_alert_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function verify(): void
    {
        $this->update(['status' => 'verified']);
    }

    public function markAsFalsePositive(): void
    {
        $this->update(['status' => 'false_positive']);
    }

    public function markAsDuplicate(): void
    {
        $this->update(['status' => 'duplicate']);
    }

    public function getDistanceFromLastSeen(): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        $alert = $this->alert;
        if (!$alert->last_seen_latitude || !$alert->last_seen_longitude) {
            return null;
        }

        // Haversine formula
        $earthRadius = 6371; // km

        $latFrom = deg2rad($alert->last_seen_latitude);
        $lonFrom = deg2rad($alert->last_seen_longitude);
        $latTo = deg2rad($this->latitude);
        $lonTo = deg2rad($this->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }
}

