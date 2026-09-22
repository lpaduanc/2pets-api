<?php

namespace App\DataTransferObjects;

use App\Models\Product;
use App\Models\ProductBatch;
use Carbon\CarbonInterface;

/**
 * Resultado de `ClinicalStockDeductionService::lockAndValidate()` — o produto (e, quando
 * `track_batches`, o lote) já travados e validados para a baixa clínica de 1 unidade.
 */
final readonly class LockedClinicalStock
{
    public function __construct(
        public Product $product,
        public ?ProductBatch $batch,
    ) {}

    /** Validade a herdar no registro clínico quando o profissional não informou uma — item 3 do parecer. */
    public function expiryDate(): ?CarbonInterface
    {
        return $this->batch?->expires_at ?? $this->product->expiry_date;
    }
}
