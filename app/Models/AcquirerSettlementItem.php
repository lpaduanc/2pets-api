<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liga um depósito da adquirente aos `sale_receipts` que o compõem — contrato
 * docs/gap-simplesvet/04. Guarda `amount` próprio (e não só a FK) porque um recebimento
 * parcelado é depositado em parcelas: o mesmo recebimento aparece em vários depósitos, com
 * valores diferentes.
 */
class AcquirerSettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'acquirer_settlement_id',
        'sale_receipt_id',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AcquirerSettlement::class, 'acquirer_settlement_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SaleReceipt::class, 'sale_receipt_id');
    }
}
