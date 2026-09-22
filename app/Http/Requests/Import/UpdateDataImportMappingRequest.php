<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT data-imports/{id}/mapping` — coluna da planilha => campo canônico do 2pets. O usuário
 * sempre confirma/ajusta a sugestão de `ColumnMapper` antes de validar (item 26).
 */
class UpdateDataImportMappingRequest extends FormRequest
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
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return $this->validated('mapping');
    }
}
