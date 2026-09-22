<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/** `message-templates` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
class StoreMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'in:email,sms,whatsapp,push'],
            'category' => ['required', 'in:transactional,marketing'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
