<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\FiscalEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CSC da NFC-e — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §4. O
 * identificador tem até 6 dígitos ("000001"); o token, de 16 a 36 caracteres alfanuméricos
 * (formato que as SEFAZ emitem no portal do contribuinte).
 */
class UpdateFiscalNfceCscRequest extends FormRequest
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
            'environment' => ['required', Rule::enum(FiscalEnvironment::class)],
            'csc_id' => ['required', 'string', 'regex:/^\d{1,6}$/'],
            'csc_token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,36}$/'],
        ];
    }
}
