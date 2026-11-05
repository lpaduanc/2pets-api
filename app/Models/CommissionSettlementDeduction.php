<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quanto de um `CommissionClawback` um fechamento descontou (PLANO 2.3). */
class CommissionSettlementDeduction extends Model
{
    protected $fillable = ['commission_settlement_id', 'commission_clawback_id', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function clawback(): BelongsTo
    {
        return $this->belongsTo(CommissionClawback::class, 'commission_clawback_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }
}
