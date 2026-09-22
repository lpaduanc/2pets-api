<?php

namespace App\Http\Requests\Exam;

use App\Rules\ScopedExamTypeExists;
use Illuminate\Foundation\Http\FormRequest;

/** Corpo de `POST exam-requests` — o pedido de exame como documento (spec 16). */
class StoreExamRequestRequest extends FormRequest
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
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            'clinical_notes' => ['nullable', 'string', 'max:2000'],
            'exam_type_ids' => ['nullable', 'array'],
            'exam_type_ids.*' => ['integer', new ScopedExamTypeExists($this->user())],
        ];
    }
}
