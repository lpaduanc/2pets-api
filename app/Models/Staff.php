<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    protected $fillable = [
        'professional_id',
        'user_id',
        'role',
        'employee_id',
        'hire_date',
        'termination_date',
        'employment_type',
        'hourly_rate',
        'monthly_salary',
        'permissions',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'monthly_salary' => 'decimal:2',
        'permissions' => 'array',
        'is_active' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'assigned_staff_id');
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions);
    }

    public function isAvailableOn(\DateTime $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Check if on time off
        $isOnTimeOff = $this->timeOff()
            ->where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();

        return !$isOnTimeOff;
    }
}

