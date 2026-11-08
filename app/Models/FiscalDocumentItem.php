<?php

namespace App\Models;

use App\Enums\TaxProfileSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item congelado do documento fiscal — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.1/§3.2. Gravado uma vez, na emissão, por `FiscalDocumentIssuer`; nunca recalculado.
 */
class FiscalDocumentItem extends Model
{
    /** Campos monetários (2 casas) que a devolução espelha pelo rateio acumulado. */
    public const MONEY_FIELDS = [
        'gross_total', 'discount_share', 'surcharge_share', 'total', 'icms_base', 'icms_amount',
        'icms_sn_credit_amount', 'fcp_amount', 'pis_base', 'pis_amount', 'cofins_base',
        'cofins_amount', 'ipi_amount', 'icms_st_amount',
    ];

    protected $fillable = [
        'fiscal_document_id',
        'line_number',
        'sale_item_id',
        'sale_return_item_id',
        'purchase_return_item_id',
        'product_id',
        'description',
        'ncm',
        'cest',
        'unit',
        'quantity',
        'unit_price',
        'gross_total',
        'discount_share',
        'surcharge_share',
        'total',
        'cfop',
        'origin',
        'csosn',
        'cst',
        'icms_base',
        'icms_rate',
        'icms_amount',
        'icms_sn_credit_rate',
        'icms_sn_credit_amount',
        'icms_base_reduction_percent',
        'fcp_rate',
        'fcp_amount',
        'pis_cst',
        'pis_base',
        'pis_rate',
        'pis_amount',
        'cofins_cst',
        'cofins_base',
        'cofins_rate',
        'cofins_amount',
        'ipi_amount',
        'icms_st_amount',
        'benefit_code',
        'tax_profile_id',
        'profile_source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $money = array_fill_keys(self::MONEY_FIELDS, 'decimal:2');

        return $money + [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'icms_rate' => 'decimal:4',
            'icms_sn_credit_rate' => 'decimal:4',
            'icms_base_reduction_percent' => 'decimal:4',
            'fcp_rate' => 'decimal:4',
            'pis_rate' => 'decimal:4',
            'cofins_rate' => 'decimal:4',
            'profile_source' => TaxProfileSource::class,
        ];
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class)->withTrashed();
    }
}
