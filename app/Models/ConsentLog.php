<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit log of consent changes.
 * No `updated_at` — entries are write-once by design (LGPD audit trail).
 */
class ConsentLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'consent_key',
        'granted',
        'source',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'granted' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
