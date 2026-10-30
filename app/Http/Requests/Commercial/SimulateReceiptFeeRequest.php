<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CardBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Prévia de taxa e agenda no PDV, antes de gravar o recebimento (docs/gap-petflow 1.1). */
class SimulateReceiptFeeRequest extends FormRequest
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
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
            'card_brand' => ['nullable', Rule::in(CardBrand::values())],
            'received_at' => ['nullable', 'date'],
        ];
    }
}
