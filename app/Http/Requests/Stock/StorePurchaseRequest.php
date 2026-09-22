<?php

namespace App\Http\Requests\Stock;

use App\Enums\ProductPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compra (rascunho) — docs/gap-simplesvet/06 e docs/gap-simplesvet/06-07-contrato-api.md.
 * Mesmo corpo no store e no update; `items` substitui a lista inteira.
 */
class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('invoice_key')) {
            $this->merge(['invoice_key' => preg_replace('/\D/', '', (string) $this->input('invoice_key'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'required_without:supplier', 'integer'],
            'supplier' => ['nullable', 'array'],
            ...StoreSupplierRequest::supplierRules('supplier.'),

            'purchase_order_id' => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:20'],
            'invoice_series' => ['nullable', 'string', 'max:5'],
            'invoice_key' => ['nullable', 'string', 'size:44'],
            'invoice_issued_at' => ['nullable', 'date'],
            'entered_at' => ['nullable', 'date'],
            'total_freight' => ['nullable', 'numeric', 'min:0'],
            'total_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'xml_token' => ['nullable', 'string', 'uuid'],

            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['nullable', 'required_without:items.*.new_product', 'integer'],
            'items.*.new_product' => ['nullable', 'array'],
            'items.*.new_product.name' => ['required_with:items.*.new_product', 'string', 'max:255'],
            'items.*.new_product.sku' => ['nullable', 'string', 'max:100'],
            'items.*.new_product.unit_of_sale' => ['nullable', 'string', 'max:10'],
            'items.*.new_product.gtin' => ['nullable', 'string', 'regex:/^\d{8}$|^\d{12,14}$/'],
            'items.*.new_product.ncm' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'items.*.new_product.product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'items.*.new_product.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'items.*.new_product.purpose' => ['nullable', Rule::enum(ProductPurpose::class)],
            'items.*.new_product.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.new_product.commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.new_product.show_in_price_list' => ['nullable', 'boolean'],
            'items.*.new_product.allow_price_override' => ['nullable', 'boolean'],
            'items.*.new_product.controls_stock' => ['nullable', 'boolean'],
            'items.*.new_product.track_batches' => ['nullable', 'boolean'],
            'items.*.new_product.min_stock' => ['nullable', 'integer', 'min:0'],
            'items.*.new_product.max_stock' => ['nullable', 'integer', 'min:0'],

            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.description_on_invoice' => ['nullable', 'string', 'max:255'],
            'items.*.supplier_product_code' => ['nullable', 'string', 'max:60'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:10'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.markup_percent' => ['nullable', 'numeric', 'min:-100', 'max:100000'],
            'items.*.applied_sale_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch' => ['nullable', 'string', 'max:60'],
            'items.*.expires_at' => ['nullable', 'date'],
            'items.*.ncm' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'items.*.purpose' => ['nullable', Rule::enum(ProductPurpose::class)],

            'payment' => ['nullable', 'array'],
            'payment.payment_method_id' => ['nullable', 'integer'],
            'payment.financial_account_id' => ['nullable', 'integer'],
            'payment.installments' => ['required_with:payment', 'integer', 'min:1', 'max:60'],
            'payment.first_due_date' => ['nullable', 'date'],
            'payment.interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.product_id.required_without' => 'Cada item precisa estar vinculado a um produto existente ou a um produto novo.',
            'invoice_key.size' => 'A chave da NF-e tem 44 dígitos.',
        ];
    }
}
