<?php

namespace App\Http\Requests\Commercial;

use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Forma de recebimento/pagamento da clínica — contrato docs/gap-simplesvet/04. Serve store e
 * update.
 *
 * `settlement_days` e `max_installments` ficam livres por tipo: quem define o prazo da
 * maquininha é o contrato com a adquirente, não o sistema. A única trava é a coerência do
 * `max_installments` com `kind`, validada abaixo — Pix em 12x não existe, e aceitar o cadastro
 * produziria um modal de recebimento oferecendo parcelamento que a maquininha recusa.
 */
class StorePaymentMethodRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'kind' => [$required, Rule::enum(PaymentMethodKind::class)],
            'acquirer' => ['nullable', 'string', 'max:30'],
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'default_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'settlement_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $kind = $this->filled('kind') ? PaymentMethodKind::tryFrom($this->string('kind')->toString()) : null;

            if ($kind !== null && ! $kind->allowsInstallments() && $this->integer('max_installments') > 1) {
                $validator->errors()->add(
                    'max_installments',
                    sprintf('%s não aceita parcelamento.', $kind->label())
                );
            }
        });
    }
}
