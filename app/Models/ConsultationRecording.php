<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationRecording extends Model
{
    protected $fillable = [
        'video_consultation_id',
        'recording_id',
        'recording_url',
        'duration_seconds',
        'status',
        'consent_given',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'consent_given' => 'boolean',
    ];

    public function videoConsultation(): BelongsTo
    {
        return $this->belongsTo(VideoConsultation::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasConsent(): bool
    {
        return $this->consent_given;
    }
}

