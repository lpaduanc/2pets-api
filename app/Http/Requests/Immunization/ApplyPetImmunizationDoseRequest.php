<?php

namespace App\Http\Requests\Immunization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mesmos campos opcionais de vínculo de estoque já usados em `PetHealthRecordsController`. */
class ApplyPetImmunizationDoseRequest extends FormRequest
{
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
            'applied_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'product_batch_id' => ['nullable', 'integer', Rule::exists('product_batches', 'id')],
            'confirm_expired' => ['nullable', 'boolean'],
        ];
    }
}
