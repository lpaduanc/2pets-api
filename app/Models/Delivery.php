<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = [
        'order_id',
        'carrier',
        'tracking_number',
        'status',
        'estimated_delivery',
        'delivered_at',
        'tracking_history',
    ];

    protected $casts = [
        'estimated_delivery' => 'datetime',
        'delivered_at' => 'datetime',
        'tracking_history' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function addTrackingEvent(string $event, string $location = null): void
    {
        $history = $this->tracking_history ?? [];

        $history[] = [
            'event' => $event,
            'location' => $location,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->update(['tracking_history' => $history]);
    }
}

