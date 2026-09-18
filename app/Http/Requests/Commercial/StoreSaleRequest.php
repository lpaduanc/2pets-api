<?php

namespace App\Http\Requests\Commercial;

use App\Enums\FiscalOperation;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nova venda ou orçamento — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * `client_id` é OPCIONAL de propósito: o balcão vende para quem entra na loja sem cadastro
 * ("consumidor não identificado"). Exigir cliente transformaria toda venda de R$ 12,00 de
 * areia sanitária num cadastro completo.
 *
 * `pet_id` só faz sentido com `client_id`, e a regra abaixo garante isso — vender um serviço
 * para um animal sem saber de quem ele é deixaria o histórico do pet sem dono.
 */
class StoreSaleRequest extends FormRequest
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
            'kind' => ['nullable', Rule::enum(SaleKind::class)],
            'fiscal_operation' => ['nullable', Rule::enum(FiscalOperation::class)],
            'status' => ['nullable', Rule::enum(SaleStatus::class)->only([SaleStatus::OPEN, SaleStatus::IN_SERVICE])],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'pet_id' => ['nullable', 'integer', 'exists:pets,id'],
            'cash_register_id' => ['nullable', 'integer', 'exists:cash_registers,id'],
            'printed_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('pet_id') && ! $this->filled('client_id')) {
                $validator->errors()->add('client_id', 'Informe o tutor ao vincular um animal à venda.');
            }
        });
    }
}
