<?php

namespace App\Http\Requests\Document;

use App\Enums\DocumentTemplateKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(DocumentTemplateKind::values())],
            'body_html' => ['required', 'string'],
            'requires_signature' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
