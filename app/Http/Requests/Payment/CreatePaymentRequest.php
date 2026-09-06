<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'invoice_id' => 'required|integer|exists:invoices,id',
            'payment_method' => ['required', Rule::in(['pix', 'credit_card', 'debit_card', 'boleto'])],
            'installments' => 'nullable|integer|min:1|max:12',
            'coupon_code' => 'nullable|string|max:40',
            'card_token' => 'nullable|string|max:255',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'invoice_id' => ['description' => 'ID da invoice que está sendo paga.'],
            'payment_method' => ['description' => 'Método: pix, credit_card, debit_card ou boleto.'],
            'installments' => ['description' => 'Número de parcelas (1–12). Só aplicável a cartão.'],
            'coupon_code' => ['description' => 'Código de cupom opcional.'],
            'card_token' => ['description' => 'Token do cartão tokenizado pelo SDK (nunca o PAN).'],
        ];
    }
}
