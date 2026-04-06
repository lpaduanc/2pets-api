<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

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
            'status' => ['sometimes', 'in:scheduled,confirmed,in_progress,completed,cancelled,no_show'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
        ];
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
