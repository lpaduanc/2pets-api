<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelamento de documento fiscal — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Justificativa de 15 a 255
 * caracteres: é o `xJust` do evento 110111 na SEFAZ (spec fase4-transmissao-propria-nfe-nfce §7.4).
 */
class StoreFiscalCancellationRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:15', 'max:255']];
    }
}
