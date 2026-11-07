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
            // XLSX (PLANO, Fase 4) além de CSV/TXT; `.xls` (binário antigo) fica de fora — o Excel
            // salva em xlsx desde 2007 e o leitor só cobre o formato aberto.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'extensions:csv,txt,xlsx', 'max:10240'],
            'entity' => ['required', Rule::enum(ImportEntity::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Envie a planilha em .xlsx ou .csv (o formato .xls antigo não é aceito: salve como .xlsx).',
            'file.extensions' => 'Envie a planilha em .xlsx ou .csv (o formato .xls antigo não é aceito: salve como .xlsx).',
        ];
    }

    public function entity(): ImportEntity
    {
        return ImportEntity::from($this->string('entity')->toString());
    }
}
