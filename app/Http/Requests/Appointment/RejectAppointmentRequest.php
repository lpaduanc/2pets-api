<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST professional/appointments/{id}/reject` — motivo opcional (o tutor só vê o motivo
 * quando o profissional informa um).
 */
class RejectAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'reason' => ['description' => 'Motivo da recusa, exibido ao tutor. Omitido: o tutor só vê que o agendamento foi recusado.'],
        ];
    }
}
