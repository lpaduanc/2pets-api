<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class StoreGeneratedDocumentRequest extends FormRequest
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
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            'medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id'],
        ];
    }
}
