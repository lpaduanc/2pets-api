<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Memória "código do item no fornecedor → nosso produto", aprendida a cada compra recebida. */
class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id',
        'product_id',
        'supplier_product_code',
        'conversion_factor',
        'purchase_unit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Itens por embalagem de compra (caixa de 12 → 12.000): a nota entra em caixa e o
            // estoque em unidade (docs/gap-petflow/PLANO.md, 1.2).
            'conversion_factor' => 'decimal:3',
        ];
    }

    /**
     * Aprende (ou corrige) o vínculo "código do fornecedor → produto". A embalagem (fator de
     * conversão, unidade de compra) só muda quando informada — o recebimento reaprende o produto
     * sem apagar o fator já confirmado.
     *
     * @param  array{conversion_factor?: string, purchase_unit?: string|null}  $packaging
     */
    public static function remember(int $supplierId, string $supplierProductCode, int $productId, array $packaging = []): self
    {
        return self::updateOrCreate(
            ['supplier_id' => $supplierId, 'supplier_product_code' => $supplierProductCode],
            ['product_id' => $productId] + $packaging,
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
