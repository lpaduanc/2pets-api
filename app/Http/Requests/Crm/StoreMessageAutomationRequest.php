<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/** `message-automations` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
class StoreMessageAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'trigger' => ['required', 'in:vaccine_due,vaccine_overdue,deworming_due,birthday_pet,birthday_client,post_appointment_followup,inactive_client'],
            'offset_days' => ['sometimes', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
