<?php

namespace App\Http\Requests\Exam;

use App\Enums\ExamTypeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamTypeRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'category' => ['sometimes', Rule::in(ExamTypeCategory::values())],
            'presentation_html' => ['nullable', 'string'],
            'closing_html' => ['nullable', 'string'],
            'preparation_instructions' => ['nullable', 'string'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
