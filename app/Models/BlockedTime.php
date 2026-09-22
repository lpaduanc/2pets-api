<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlockedTime extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'professional_id',
        'organization_id',
        'location_id',
        'start_datetime',
        'end_datetime',
        'reason',
        'deleted_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'location_id' => 'integer',
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /**
     * Quem removeu este bloqueio (item 21 — histórico de bloqueio geral). Nulo enquanto o
     * registro está ativo.
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
