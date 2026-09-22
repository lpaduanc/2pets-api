<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `message-campaigns` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `client_segment_id` (segmento salvo, 18) e `ad_hoc_client_ids` (lista pontual, 25) são
 * mutuamente exclusivos — exatamente um dos dois precisa vir preenchido.
 */
class StoreMessageCampaignRequest extends FormRequest
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
            'message_template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'client_segment_id' => ['nullable', 'integer', 'exists:client_segments,id'],
            'ad_hoc_client_ids' => ['nullable', 'array'],
            'ad_hoc_client_ids.*' => ['integer'],
            'scheduled_for' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasSegment = $this->filled('client_segment_id');
            $hasAdHoc = ! empty($this->input('ad_hoc_client_ids'));

            if ($hasSegment === $hasAdHoc) {
                $validator->errors()->add('client_segment_id', 'Informe exatamente um: segmento salvo OU lista de clientes.');
            }
        });
    }
}
