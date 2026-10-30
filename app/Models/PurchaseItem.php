<?php

namespace App\Models;

use App\Enums\ProductPurpose;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    /** Custo unitário de aquisição: 4 casas (plano, seção 3) — o custo médio do produto é que tem 2. */
    public const LANDED_UNIT_COST_SCALE = 4;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'purchase_order_item_id',
        'supplier_product_code',
        'description_on_invoice',
        'quantity',
        'unit',
        'unit_cost',
        'discount',
        'freight_share',
        'insurance_share',
        'other_share',
        'ipi_amount',
        'icms_st_amount',
        'conversion_factor',
        'purchase_unit',
        'total_cost',
        'landed_total_cost',
        'landed_unit_cost',
        'markup_percent',
        'suggested_price',
        'applied_sale_price',
        'batch',
        'expires_at',
        'ncm',
        'purpose',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => ProductPurpose::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'discount' => 'decimal:2',
            'freight_share' => 'decimal:2',
            'insurance_share' => 'decimal:2',
            'other_share' => 'decimal:2',
            'ipi_amount' => 'decimal:2',
            'icms_st_amount' => 'decimal:2',
            'conversion_factor' => 'decimal:3',
            'total_cost' => 'decimal:2',
            'landed_total_cost' => 'decimal:2',
            'landed_unit_cost' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'suggested_price' => 'decimal:2',
            'applied_sale_price' => 'decimal:2',
            'expires_at' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * Custo unitário EFETIVO por unidade de ESTOQUE: o custo real congelado na compra
     * (docs/gap-petflow/PLANO.md, 1.2 — linha − desconto + frete + seguro + outras + IPI + ST,
     * dividido por quantidade × fator). Só para exibir e para o último custo — o estoque recebe o
     * `landed_total_cost` exato (`PurchaseService::receiveItem`), não este × quantidade.
     */
    public function effectiveUnitCost(): string
    {
        return (string) Decimal::of($this->landed_unit_cost)->toScale(self::LANDED_UNIT_COST_SCALE, RoundingMode::HalfUp);
    }

    /** Unidades que entram no estoque: 5 caixas × fator 12 = 60 un (até 4 casas, validado na gravação). */
    public function stockQuantity(): Quantity
    {
        return Quantity::of(Decimal::of($this->quantity)->multipliedBy(Decimal::of($this->conversion_factor ?? 1)));
    }
}
