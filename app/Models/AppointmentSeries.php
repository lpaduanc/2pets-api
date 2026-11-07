<?php

namespace App\Models;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Série de agendamentos recorrentes (PLANO 3.1) — a regra e o molde de cada ocorrência. */
class AppointmentSeries extends Model
{
    protected $table = 'appointment_series';

    protected $fillable = [
        'organization_id', 'professional_id', 'client_id', 'pet_id', 'type', 'appointment_type_id', 'service_id', 'duration', 'price',
        'services', 'reason', 'notes', 'sold_package_id', 'freq', 'interval', 'weekdays', 'until', 'count', 'timezone', 'start_date',
        'start_time', 'skipped_indices', 'last_index', 'materialized_until', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'freq' => RecurrenceFrequency::class,
            'interval' => 'integer',
            'weekdays' => 'array',
            'until' => 'date',
            'count' => 'integer',
            'start_date' => 'date',
            'services' => 'array',
            'skipped_indices' => 'array',
            'last_index' => 'integer',
            'materialized_until' => 'date',
            'price' => 'decimal:2',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'series_id');
    }

    public function startDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_date->toDateString());
    }

    /** @return array{id: int, freq: string, interval: int, weekdays: list<int>|null, until: string|null, count: int|null} */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'freq' => $this->freq->value,
            'interval' => $this->interval,
            'weekdays' => $this->weekdays,
            'until' => $this->until?->toDateString(),
            'count' => $this->count,
        ];
    }
}
