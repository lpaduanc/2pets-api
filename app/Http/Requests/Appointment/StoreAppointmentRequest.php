<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by auth:sanctum middleware
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:users,id'],
            'pet_id' => ['required', 'exists:pets,id'],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'duration' => ['nullable', 'integer', 'min:15', 'max:480'],
            'type' => ['required', 'in:consultation,surgery,vaccination,exam,emergency,grooming,checkup'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente e obrigatorio.',
            'client_id.exists' => 'Cliente nao encontrado.',
            'pet_id.required' => 'O pet e obrigatorio.',
            'pet_id.exists' => 'Pet nao encontrado.',
            'appointment_date.required' => 'A data do agendamento e obrigatoria.',
            'appointment_date.after_or_equal' => 'A data do agendamento deve ser hoje ou no futuro.',
            'appointment_time.required' => 'O horario e obrigatorio.',
            'appointment_time.date_format' => 'O horario deve estar no formato HH:mm.',
            'type.required' => 'O tipo de consulta e obrigatorio.',
            'type.in' => 'Tipo de consulta invalido.',
            'duration.min' => 'A duracao minima e de 15 minutos.',
            'price.min' => 'O preco deve ser um valor positivo.',
        ];
    }
}
