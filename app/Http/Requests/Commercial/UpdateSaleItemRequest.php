<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajuste de uma linha de venda já lançada — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 * Se o preço pode mudar, e se o funcionário é da clínica, é regra do `SaleService::updateItem`.
 */
class UpdateSaleItemRequest extends FormRequest
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
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:999999'],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'discount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'staff_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'A quantidade deve ser maior que zero.',
        ];
    }
}
