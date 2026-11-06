<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte de um lançamento num centro de custo (PLANO 2.4). */
class FinancialEntryAllocation extends Model
{
    protected $fillable = ['financial_entry_id', 'cost_center_id', 'percent', 'amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['percent' => 'decimal:4', 'amount' => 'decimal:2'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed();
    }
}
