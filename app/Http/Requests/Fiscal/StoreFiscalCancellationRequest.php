<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelamento de documento fiscal — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
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
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
