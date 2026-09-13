<?php

namespace App\Models;

use App\Enums\ExamResultStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamResult extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exam_id',
        'parameter',
        'value',
        'value_numeric',
        'unit',
        'reference_range',
        'reference_min',
        'reference_max',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
            'reference_min' => 'decimal:4',
            'reference_max' => 'decimal:4',
            'status' => ExamResultStatus::class,
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function isNormal(): bool
    {
        return $this->status === ExamResultStatus::NORMAL;
    }

    public function isCritical(): bool
    {
        return $this->status === ExamResultStatus::CRITICAL;
    }
}
