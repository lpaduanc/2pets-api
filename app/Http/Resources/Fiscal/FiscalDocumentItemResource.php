<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalDocumentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item congelado do documento (spec fase4-fiscal §3.1/§3.2). Dinheiro como string de 2 casas,
 * alíquota e quantidade com 4.
 *
 * @mixin FiscalDocumentItem
 */
class FiscalDocumentItemResource extends JsonResource
{
    private const TEXT_FIELDS = [
        'id', 'line_number', 'sale_item_id', 'sale_return_item_id', 'purchase_return_item_id', 'product_id',
        'description', 'ncm', 'cest', 'unit', 'cfop', 'origin', 'csosn', 'cst', 'pis_cst', 'cofins_cst',
        'benefit_code', 'tax_profile_id',
    ];

    private const DECIMAL_FIELDS = [
        'quantity', 'unit_price', 'icms_rate', 'icms_base_reduction_percent', 'icms_sn_credit_rate', 'fcp_rate',
        'pis_rate', 'cofins_rate', ...FiscalDocumentItem::MONEY_FIELDS,
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->only(self::TEXT_FIELDS)
            + array_map(fn ($value): string => (string) $value, $this->resource->only(self::DECIMAL_FIELDS))
            + ['profile_source' => $this->profile_source?->value];
    }
}
