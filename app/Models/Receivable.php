<?php

namespace App\Models;

use App\Enums\CardBrand;
use App\Enums\ReceivableStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma parcela que a adquirente vai depositar — docs/gap-petflow/PLANO.md, 1.1. Gerada junto
 * com o recebimento (`ReceivableScheduleService`) e congelada: mudar a taxa no cadastro depois
 * não altera parcela já gerada.
 */
class Receivable extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_receipt_id',
        'payment_method_id',
        'card_brand',
        'installment_number',
        'installments_count',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'expected_date',
        'status',
        'anticipation_id',
        'settled_at',
        'acquirer_settlement_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_brand' => CardBrand::class,
            'status' => ReceivableStatus::class,
            'installment_number' => 'integer',
            'installments_count' => 'integer',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'expected_date' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SaleReceipt::class, 'sale_receipt_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AcquirerSettlement::class, 'acquirer_settlement_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReceivableStatus::PENDING->value);
    }
}
