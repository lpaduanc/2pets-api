<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT professional/deposit-settings` — Fase 6 do fluxo de agendamento. Percentual
 * limitado a 0–50: acima disso deixa de ser "sinal" (adiantamento parcial) e vira
 * cobrança quase integral antecipada, fora do que o dono do produto pediu. `0` é aceito de
 * propósito — equivale a desligado na resolução (`DepositConfigResolver`), então um
 * estabelecimento pode "zerar" sem precisar desmarcar o toggle.
 */
class UpdateDepositSettingsRequest extends FormRequest
{
    private const MAX_PERCENTAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deposit_enabled' => ['required', 'boolean'],
            'deposit_percentage' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_PERCENTAGE],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->boolean('deposit_enabled') && ! $this->filled('deposit_percentage')) {
                $validator->errors()->add('deposit_percentage', 'Informe o percentual do sinal.');
            }
        });
    }

    public function bodyParameters(): array
    {
        return [
            'deposit_enabled' => ['description' => 'Liga/desliga a cobrança de sinal para este estabelecimento.'],
            'deposit_percentage' => ['description' => 'Percentual do preço do serviço cobrado como sinal (0 a 50). Obrigatório quando deposit_enabled é true.'],
        ];
    }
}
