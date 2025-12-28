<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Location extends Model
{
    protected $fillable = [
        'professional_id',
        'name',
        'address',
        'city',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'opening_hours',
        'working_days',
        'is_primary',
        'is_active',
        'amenities',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'opening_hours' => 'array',
        'working_days' => 'array',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'amenities' => 'array',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'location_staff', 'location_id', 'staff_id')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    public function getFullAddressAttribute(): string
    {
        return "{$this->address}, {$this->city} - {$this->state}, {$this->zip_code}";
    }

    public function isOpenNow(): bool
    {
        $now = now();
        $dayOfWeek = strtolower($now->format('l'));

        if (!in_array($dayOfWeek, $this->working_days ?? [])) {
            return false;
        }

        $openingHours = $this->opening_hours[$dayOfWeek] ?? null;
        
        if (!$openingHours) {
            return false;
        }

        $currentTime = $now->format('H:i');
        return $currentTime >= $openingHours['open'] && $currentTime <= $openingHours['close'];
    }
}

