<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST clients/{client}/messages` — envio avulso, fora de campanha/automação. Contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 */
class StoreClientMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'in:email,sms,whatsapp'],
            'category' => ['sometimes', 'in:transactional,marketing'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ];
    }
}
