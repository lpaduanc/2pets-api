<?php

namespace App\Http\Requests\Commercial;

use App\Enums\FiscalOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cabeçalho da venda — "Alterar Cliente" e dados impressos, contrato
 * docs/gap-simplesvet/01-caixa-pdv.md. Quais campos podem mudar em qual situação é regra do
 * `SaleService::updateDetails`, não daqui.
 */
class UpdateSaleRequest extends FormRequest
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
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'pet_id' => ['sometimes', 'nullable', 'integer'],
            'fiscal_operation' => ['sometimes', Rule::enum(FiscalOperation::class)],
            'printed_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
