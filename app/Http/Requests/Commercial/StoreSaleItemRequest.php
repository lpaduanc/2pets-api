<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Linha de venda — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * `staff_id` (funcionário responsável pelo item) é o que alimenta a comissão do doc 09. Fica
 * opcional aqui porque a venda de produto no balcão nem sempre tem responsável — mas quando
 * vem, tem que ser um vínculo real de `organization_members`.
 *
 * `unit_price` é opcional: omitido, vale o preço do cadastro. A recusa de preço diferente em
 * item com `allow_price_override = false` NÃO é validada aqui (o Form Request não conhece o
 * produto) — é do `SaleService`, que lança `PriceOverrideNotAllowedException`.
 */
class StoreSaleItemRequest extends FormRequest
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
            'sellable_type' => ['required', Rule::in(['product', 'service'])],
            'sellable_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'staff_id' => ['nullable', 'integer', 'exists:organization_members,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sellable_type.in' => 'Tipo de item inválido. Use "product" ou "service".',
            'quantity.gt' => 'A quantidade deve ser maior que zero.',
        ];
    }
}
