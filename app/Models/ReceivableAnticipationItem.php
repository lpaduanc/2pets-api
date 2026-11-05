<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma parcela numa antecipação (PLANO 2.1): taxa, dias e a data original de depósito. */
class ReceivableAnticipationItem extends Model
{
    protected $fillable = ['receivable_anticipation_id', 'receivable_id', 'net_amount', 'fee_amount', 'days', 'original_expected_date'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['net_amount' => 'decimal:2', 'fee_amount' => 'decimal:2', 'days' => 'integer', 'original_expected_date' => 'date'];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
