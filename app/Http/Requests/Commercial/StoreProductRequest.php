<?php

namespace App\Http\Requests\Commercial;

use App\Enums\ProductPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md.
 *
 * `price` é `required_without_all:markup_percent` de propósito: o balcão aceita definir o
 * preço direto OU deixar o markup derivá-lo do custo, mas nunca ficar sem nenhum dos dois —
 * ver `PricingService::resolvePricing()`, que faz a conversão depois desta validação passar.
 */
class StoreProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sku' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:60'],

            // GTIN-8/12/13/14: só dígitos, nos comprimentos que o padrão define. Aceitar
            // qualquer string aqui faria `products/lookup?gtin=` devolver lixo.
            'gtin' => ['nullable', 'string', 'regex:/^\d{8}$|^\d{12,14}$/'],
            'ncm' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'cest' => ['nullable', 'string', 'regex:/^\d{7}$/'],
            'unit_of_sale' => ['nullable', 'string', 'max:10'],
            'purpose' => ['nullable', Rule::enum(ProductPurpose::class)],

            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],

            'price' => ['required_without_all:markup_percent', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'average_cost' => ['nullable', 'numeric', 'min:0'],
            'last_cost' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:-100', 'max:100000'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0', 'gte:min_stock'],
            'expiry_date' => ['nullable', 'date'],

            'show_in_price_list' => ['nullable', 'boolean'],
            'allow_price_override' => ['nullable', 'boolean'],
            'controls_stock' => ['nullable', 'boolean'],
            'track_batches' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required_without_all' => 'Informe o preço de venda ou um markup para calculá-lo a partir do custo.',
            'gtin.regex' => 'Código de barras deve ter 8, 12, 13 ou 14 dígitos.',
            'ncm.regex' => 'NCM deve ter exatamente 8 dígitos.',
            'cest.regex' => 'CEST deve ter exatamente 7 dígitos.',
            'max_stock.gte' => 'Estoque máximo não pode ser menor que o mínimo.',
        ];
    }
}
