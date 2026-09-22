<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/public/booking` — Fase 2, item 6: `professional_id` deixou de ser
 * obrigatório. Sem ele, `organization_id` é obrigatório e `BookingService` resolve um
 * profissional concreto da equipe (modo "qualquer profissional disponível") — nenhum
 * agendamento sai sem `professional_id` gravado, só o payload pode omiti-lo na entrada.
 */
class BookAppointmentRequest extends FormRequest
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
            'professional_id' => ['nullable', 'integer', 'exists:users,id'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'pet_id' => ['nullable', 'integer', 'exists:pets,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('professional_id') && ! $this->filled('organization_id')) {
                $validator->errors()->add('professional_id', 'Informe professional_id ou organization_id.');
            }
        });
    }

    public function bodyParameters(): array
    {
        return [
            'professional_id' => ['description' => 'Profissional escolhido. Omitido + organization_id: o servidor resolve um profissional disponível da equipe.'],
            'organization_id' => ['description' => 'Estabelecimento do agendamento — obrigatório quando professional_id é omitido.'],
            'location_id' => ['description' => 'Local específico do estabelecimento, quando houver mais de um.'],
            'service_id' => ['description' => 'Serviço a ser prestado.'],
            'pet_id' => ['description' => 'Pet do tutor autenticado.'],
            'appointment_date' => ['description' => 'Data e hora do agendamento (ISO 8601).'],
            'notes' => ['description' => 'Observações do tutor.'],
        ];
    }
}
