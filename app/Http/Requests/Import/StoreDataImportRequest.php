<?php

namespace App\Http\Requests\Import;

use App\Enums\Import\ImportEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload de planilha (item 26 do backlog gap-simplesvet). Autorização de rota é o middleware
 * `permission:data.import` (`routes/api.php`) — este Form Request só valida forma do payload.
 */
class StoreDataImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'entity' => ['required', Rule::enum(ImportEntity::class)],
        ];
    }

    public function entity(): ImportEntity
    {
        return ImportEntity::from($this->string('entity')->toString());
    }
}
