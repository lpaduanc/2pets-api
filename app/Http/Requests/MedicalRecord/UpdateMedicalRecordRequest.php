<?php

namespace App\Http\Requests\MedicalRecord;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'weight' => 'nullable|numeric|min:0|max:999.99',
            'temperature' => 'nullable|numeric|min:20|max:50',
            'heart_rate' => 'nullable|integer|min:0|max:500',
            'respiratory_rate' => 'nullable|integer|min:0|max:200',
            'subjective' => 'nullable|string|max:5000',
            'objective' => 'nullable|string|max:5000',
            'assessment' => 'nullable|string|max:5000',
            'plan' => 'nullable|string|max:5000',
            'symptoms' => 'nullable|array|max:50',
            'symptoms.*' => 'string|max:200',
            'diagnosis' => 'nullable|string|max:2000',
            'treatment_plan' => 'nullable|string|max:5000',
            'prescriptions' => 'nullable|array|max:20',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
