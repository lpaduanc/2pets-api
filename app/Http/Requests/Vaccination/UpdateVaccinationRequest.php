<?php

namespace App\Http\Requests\Vaccination;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVaccinationRequest extends FormRequest
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
            'vaccine_name' => ['sometimes', 'required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'application_date' => ['sometimes', 'date'],
            'next_dose_date' => ['nullable', 'date'],
            'dose_number' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'adverse_reactions' => ['nullable', 'string'],
        ];
    }
}
