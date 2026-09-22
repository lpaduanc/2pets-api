<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/** Cabeçalho do rascunho de orçamento — docs/gap-simplesvet/24-orcamentos.md. */
class UpdateQuoteRequest extends FormRequest
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
            'client_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'pet_id' => ['sometimes', 'nullable', 'integer', 'exists:pets,id'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'printed_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
