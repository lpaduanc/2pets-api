<?php

namespace App\Http\Requests\Appointment;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    public function rules(): array
    {
        return [
            'appointment_date' => ['sometimes', 'date'],
            'appointment_time' => ['sometimes', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:480'],
            'type' => ['sometimes', 'in:consultation,surgery,vaccination,exam,emergency,grooming,checkup'],
            'status' => ['sometimes', Rule::in($this->settableStatusValues())],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
        ];
    }

    /**
     * `pending` fica de fora: só `BookingService` grava esse status (agendamento do tutor
     * aguardando confirmação) — o profissional nunca define uma consulta como `pending` por
     * aqui. A máquina de estados em si (o que é alcançável a partir do status atual) é
     * responsabilidade de `AppointmentStatusTransitionService`, não desta validação de formato.
     *
     * @return list<string>
     */
    private function settableStatusValues(): array
    {
        return array_values(array_diff(
            array_column(AppointmentStatus::cases(), 'value'),
            [AppointmentStatus::PENDING->value]
        ));
    }

    public function messages(): array
    {
        return [
            'appointment_time.date_format' => 'O horario deve estar no formato HH:mm.',
            'type.in' => 'Tipo de consulta invalido.',
            'status.in' => 'Status invalido.',
            'duration.min' => 'A duracao minima e de 15 minutos.',
            'price.min' => 'O preco deve ser um valor positivo.',
        ];
    }
}
