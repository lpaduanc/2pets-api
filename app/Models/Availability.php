<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Availability extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'location_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration',
        'buffer_time',
        'is_active',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'location_id' => 'integer',
        'day_of_week' => 'integer',
        'slot_duration' => 'integer',
        'buffer_time' => 'integer',
        'is_active' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * `location_id` nulo é um "local" próprio na comparação — nunca casa com uma janela
     * QUE TEM local, mesmo profissional/dia/horário (ver `AvailabilityOverlapChecker`,
     * `AvailabilityManagementService::replaceWeek()`).
     */
    public function scopeAtLocation(Builder $query, ?int $locationId): Builder
    {
        return $locationId === null
            ? $query->whereNull('location_id')
            : $query->where('location_id', $locationId);
    }
}
