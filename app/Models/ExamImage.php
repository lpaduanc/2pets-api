<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamImage extends Model
{
    protected $fillable = [
        'exam_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'image_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}

