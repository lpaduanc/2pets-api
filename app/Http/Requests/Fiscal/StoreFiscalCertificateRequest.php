<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload do A1 — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §3.2. A
 * validação de verdade é abrir o PKCS#12 com a senha (`FiscalCertificateInspector`); aqui só o
 * formato do pedido. Um A1 real tem de 3 a 10 KB: o teto barra arquivo que não é certificado.
 */
class StoreFiscalCertificateRequest extends FormRequest
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
            'certificate' => ['required', 'file', 'extensions:pfx,p12', 'max:'.(int) config('fiscal.certificate.max_kilobytes')],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['certificate.extensions' => 'Envie o certificado A1 no formato .pfx ou .p12.'];
    }
}
