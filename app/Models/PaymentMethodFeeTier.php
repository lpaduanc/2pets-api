<?php

namespace App\Models;

use App\Enums\CardBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Faixa de taxa de uma forma de cartão: bandeira × parcelas (de/até) × vigência → taxa e prazo
 * (docs/gap-petflow/PLANO.md, 1.1). `card_brand` nulo vale para qualquer bandeira.
 *
 * SoftDeletes: faixa removida que um recebimento já usou fica arquivada, para o
 * `sale_receipts.fee_tier_id` continuar apontando para a taxa que valeu na venda. A relação
 * `PaymentMethod::feeTiers()` (e portanto o `FeeTierResolver`) não enxerga as arquivadas.
 */
class PaymentMethodFeeTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_method_id',
        'card_brand',
        'installments_from',
        'installments_to',
        'fee_percent',
        'fee_fixed',
        'first_settlement_days',
        'interval_days',
        'pass_to_customer',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_brand' => CardBrand::class,
            'installments_from' => 'integer',
            'installments_to' => 'integer',
            'fee_percent' => 'decimal:4',
            'fee_fixed' => 'decimal:2',
            'first_settlement_days' => 'integer',
            'interval_days' => 'integer',
            'pass_to_customer' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
