<?php

namespace App\Http\Requests\Immunization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImmunizationAdherenceReportRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
        ];
    }
}
