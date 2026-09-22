<?php

namespace App\Http\Requests\Immunization;

use Illuminate\Foundation\Http\FormRequest;

class StorePetImmunizationPlanRequest extends FormRequest
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
            'protocol_id' => ['required', 'integer', 'exists:immunization_protocols,id'],
            'started_at' => ['nullable', 'date'],
        ];
    }
}
