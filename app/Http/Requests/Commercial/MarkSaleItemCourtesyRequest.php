<?php

namespace App\Http\Requests\Commercial;

use App\Enums\SaleItemCourtesyKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `PUT sales/{sale}/items/{item}/courtesy` (PLANO 1.4): tipo e motivo, ambos obrigatórios. */
class MarkSaleItemCourtesyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(SaleItemCourtesyKind::class)],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
