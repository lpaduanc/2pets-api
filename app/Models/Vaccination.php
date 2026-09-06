<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vaccination extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'vaccine_name',
        'manufacturer',
        'batch_number',
        'application_date',
        'next_dose_date',
        'dose_number',
        'notes',
        'adverse_reactions',
    ];

    protected $casts = [
        'application_date' => 'date',
        'next_dose_date' => 'date',
        'dose_number' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('next_dose_date', '>=', today())
            ->whereNotNull('next_dose_date');
    }
}
