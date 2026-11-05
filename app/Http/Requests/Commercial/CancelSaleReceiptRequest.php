<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/** `POST sales/{sale}/receipts/{receipt}/cancel` (PLANO 2.3): o motivo é obrigatório. */
class CancelSaleReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
