<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoConsultation extends Model
{
    protected $fillable = [
        'appointment_id',
        'room_id',
        'provider',
        'status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'participants',
        'recording_enabled',
        'recording_url',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'participants' => 'array',
        'recording_enabled' => 'boolean',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(ConsultationRecording::class);
    }

    public function start(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function end(): void
    {
        $duration = $this->started_at ? now()->diffInSeconds($this->started_at) : 0;

        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
            'duration_seconds' => $duration,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'in_progress']);
    }
}

