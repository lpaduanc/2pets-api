<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    protected $fillable = [
        'exam_id',
        'parameter',
        'value',
        'unit',
        'reference_range',
        'status',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function isNormal(): bool
    {
        return $this->status === 'normal';
    }

    public function isCritical(): bool
    {
        return $this->status === 'critical';
    }
}

