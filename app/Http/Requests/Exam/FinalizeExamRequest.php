<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeExamRequest extends FormRequest
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
            'report_html' => ['nullable', 'string'],
            'findings' => ['nullable', 'string'],
            'conclusion' => ['nullable', 'string'],
        ];
    }
}
