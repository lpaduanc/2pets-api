<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'exam_type',
        'exam_name',
        'exam_date',
        'notes',
        'status',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

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

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ExamImage::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }
}

