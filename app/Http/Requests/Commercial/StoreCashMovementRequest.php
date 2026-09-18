<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Suprimento, sangria ou ajuste lançado à mão — contrato docs/gap-simplesvet/01-caixa-pdv.md
 * ("movimentos manuais com usuário, data, hora, conta de origem, valor, forma de pagamento,
 * descrição").
 *
 * Só os tipos MANUAIS são aceitos aqui: `sale_receipt` e `refund` nascem de venda, pelo
 * `SaleService`. Deixar a rota manual criar um recebimento permitiria inflar o caixa sem
 * nenhuma venda por trás.
 */
class StoreCashMovementRequest extends FormRequest
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
            'type' => [
                'required',
                Rule::enum(CashMovementType::class)->only([
                    CashMovementType::SUPPLY,
                    CashMovementType::WITHDRAWAL,
                    CashMovementType::ADJUSTMENT,
                ]),
            ],
            // Sempre positivo: a direção vem do tipo (ver `CashMovementType::signFor()` e o
            // CHECK `amount > 0` da migration).
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.Illuminate\Validation\Rules\Enum' => 'Tipo de movimento manual inválido. Use suprimento, sangria ou ajuste.',
            'amount.gt' => 'O valor do movimento deve ser maior que zero.',
            'description.required' => 'Descreva o motivo do movimento.',
        ];
    }
}
