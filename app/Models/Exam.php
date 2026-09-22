<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Exam extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'exam_type',
        'exam_name',
        'exam_date',
        'notes',
        'status',
        'exam_type_id',
        'report_html',
        'findings',
        'conclusion',
    ];

    protected $casts = [
        'exam_date' => 'date',
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

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ExamImage::class);
    }

    /** Catálogo opcional que originou o laudo pré-montado — nunca obrigatório (spec 16, regra 1). */
    public function examType(): BelongsTo
    {
        return $this->belongsTo(ExamType::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function hasReport(): bool
    {
        return $this->report_html !== null;
    }
}
