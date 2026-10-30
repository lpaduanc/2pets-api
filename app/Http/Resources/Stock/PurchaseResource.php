<?php

namespace App\Http\Resources\Stock;

use App\Models\Purchase;
use App\Models\PurchaseInstallment;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Purchase
 */
class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'legal_name' => $this->supplier->legal_name,
                'trade_name' => $this->supplier->trade_name,
                'document' => $this->supplier->document,
            ]),
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'invoice_number' => $this->invoice_number,
            'invoice_series' => $this->invoice_series,
            'invoice_key' => $this->invoice_key,
            'invoice_issued_at' => $this->invoice_issued_at?->toDateString(),
            'entered_at' => $this->entered_at?->toIso8601String(),
            'total_products' => (float) $this->total_products,
            'total_freight' => (float) $this->total_freight,
            'total_discount' => (float) $this->total_discount,
            // Totais do custo real (plano 1.2): string de 2 casas, como todo dinheiro novo.
            'total_insurance' => $this->total_insurance,
            'total_other' => $this->total_other,
            'total_ipi' => $this->total_ipi,
            'total_icms_st' => $this->total_icms_st,
            'total' => (float) $this->total,
            'has_xml' => $this->xml_path !== null,
            'notes' => $this->notes,
            'payment' => $this->installments_count === null ? null : [
                'payment_method_id' => $this->payment_method_id,
                'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? ['id' => $this->paymentMethod->id, 'name' => $this->paymentMethod->name] : null),
                'financial_account_id' => $this->financial_account_id,
                'financial_account' => $this->whenLoaded('financialAccount', fn () => $this->financialAccount ? ['id' => $this->financialAccount->id, 'name' => $this->financialAccount->name] : null),
                'installments' => $this->installments_count,
                'first_due_date' => $this->first_due_date?->toDateString(),
                'interval_days' => $this->installment_interval_days,
            ],
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (PurchaseItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'price' => (float) $item->product->price,
                    'average_cost' => $item->product->average_cost,
                    'unit_of_sale' => $item->product->unit_of_sale,
                    'decimal_places_allowed' => (int) $item->product->decimal_places_allowed,
                    'track_batches' => (bool) $item->product->track_batches,
                ] : null,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'description_on_invoice' => $item->description_on_invoice,
                'supplier_product_code' => $item->supplier_product_code,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'unit_cost' => $item->unit_cost,
                'discount' => (float) $item->discount,
                'total_cost' => (float) $item->total_cost,
                // Custo real (plano 1.2): dinheiro em string de 2 casas, custo unitário por
                // unidade de ESTOQUE em 4, fator em 3.
                'freight_share' => $item->freight_share,
                'insurance_share' => $item->insurance_share,
                'other_share' => $item->other_share,
                'ipi_amount' => $item->ipi_amount,
                'icms_st_amount' => $item->icms_st_amount,
                'conversion_factor' => $item->conversion_factor,
                'purchase_unit' => $item->purchase_unit,
                'stock_quantity' => $item->stockQuantity()->toString(),
                'landed_total_cost' => $item->landed_total_cost,
                'landed_unit_cost' => $item->landed_unit_cost,
                'markup_percent' => $item->markup_percent === null ? null : (float) $item->markup_percent,
                'suggested_price' => $item->suggested_price === null ? null : (float) $item->suggested_price,
                'applied_sale_price' => $item->applied_sale_price === null ? null : (float) $item->applied_sale_price,
                'batch' => $item->batch,
                'expires_at' => $item->expires_at?->toDateString(),
                'ncm' => $item->ncm,
                'purpose' => $item->purpose?->value,
            ])->values()),
            'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn (PurchaseInstallment $installment): array => [
                'id' => $installment->id,
                'number' => $installment->number,
                'due_date' => $installment->due_date->toDateString(),
                'amount' => (float) $installment->amount,
                'status' => $installment->status->value,
                'status_label' => $installment->status->label(),
                'payment_method' => $installment->paymentMethod ? ['id' => $installment->paymentMethod->id, 'name' => $installment->paymentMethod->name] : null,
                'financial_account' => $installment->financialAccount ? ['id' => $installment->financialAccount->id, 'name' => $installment->financialAccount->name] : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
