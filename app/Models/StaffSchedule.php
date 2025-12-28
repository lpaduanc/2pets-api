<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSchedule extends Model
{
    protected $fillable = [
        'staff_id',
        'location_id',
        'day_of_week',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_active',
    ];

    protected $casts = [
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'break_start' => 'datetime:H:i',
        'break_end' => 'datetime:H:i',
        'is_active' => 'boolean',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function getTotalHours(): float
    {
        $start = strtotime($this->start_time);
        $end = strtotime($this->end_time);
        $totalMinutes = ($end - $start) / 60;

        if ($this->break_start && $this->break_end) {
            $breakStart = strtotime($this->break_start);
            $breakEnd = strtotime($this->break_end);
            $breakMinutes = ($breakEnd - $breakStart) / 60;
            $totalMinutes -= $breakMinutes;
        }

        return $totalMinutes / 60;
    }
}

