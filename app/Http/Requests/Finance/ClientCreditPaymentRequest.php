<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cotação (`credit-payments/quote`) e recebimento (`credit-payments`) de crediário (PLANO 2.2).
 * Na cotação só a data importa; no recebimento, valor, forma, data (hoje ou antes, nunca no
 * futuro) e desconto de encargo opcional.
 */
class ClientCreditPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isPayment = ! str_ends_with($this->path(), '/quote');

        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => [$isPayment ? 'required' : 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'payment_method_id' => [$isPayment ? 'required' : 'nullable', 'integer'],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fee_discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }
}
