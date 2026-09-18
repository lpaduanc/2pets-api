<?php

namespace App\Http\Requests\Commercial;

use App\Enums\ProductPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update é PATCH-semântico: tudo `sometimes`, para que a edição inline de markup na listagem
 * (doc 08) mande só o campo alterado sem zerar o resto do cadastro.
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sku' => ['sometimes', 'string', 'max:100'],
            'code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'gtin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}$|^\d{12,14}$/'],
            'ncm' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}$/'],
            'cest' => ['sometimes', 'nullable', 'string', 'regex:/^\d{7}$/'],
            'unit_of_sale' => ['sometimes', 'string', 'max:10'],
            'purpose' => ['sometimes', Rule::enum(ProductPurpose::class)],

            'product_group_id' => ['sometimes', 'nullable', 'integer', 'exists:product_groups,id'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],

            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'average_cost' => ['sometimes', 'numeric', 'min:0'],
            'last_cost' => ['sometimes', 'numeric', 'min:0'],
            'markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:100000'],
            'commission_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'min_stock' => ['sometimes', 'integer', 'min:0'],
            'max_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],

            'show_in_price_list' => ['sometimes', 'boolean'],
            'allow_price_override' => ['sometimes', 'boolean'],
            'controls_stock' => ['sometimes', 'boolean'],
            'track_batches' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
        ];
    }
}
