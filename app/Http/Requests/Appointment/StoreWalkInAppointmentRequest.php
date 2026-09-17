<?php

namespace App\Http\Requests\Appointment;

use App\Enums\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST professional/appointments/walk-in` — contrato §3.
 *
 * `client_id` NUNCA é aceito aqui: é derivado de `pet.user_id` no servidor
 * (`ConsultationService::startWalkIn`) — ver a nota de segurança do contrato.
 */
class StoreWalkInAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            // Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md
            // §3: qualquer categoria pode virar encaixe, não só as 4 originais.
            'type' => ['required', Rule::enum(ServiceCategory::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:480'],
        ];
    }

    public function messages(): array
    {
        return [
            'pet_id.required' => 'O pet é obrigatório.',
            'pet_id.exists' => 'Pet não encontrado.',
            'type.required' => 'O tipo de atendimento é obrigatório.',
            'type.enum' => 'Tipo de atendimento inválido.',
        ];
    }
}
