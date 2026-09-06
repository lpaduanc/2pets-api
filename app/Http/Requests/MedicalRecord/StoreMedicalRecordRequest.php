<?php

namespace App\Http\Requests\MedicalRecord;

use Illuminate\Foundation\Http\FormRequest;

class StoreMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced in the controller via MedicalRecordPolicy::create($pet),
        // which requires an active PetVetAccess grant.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'pet_id' => 'required|exists:pets,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'record_date' => 'required|date',
            'weight' => 'nullable|numeric|min:0|max:999.99',
            'temperature' => 'nullable|numeric|min:20|max:50',
            'heart_rate' => 'nullable|integer|min:0|max:500',
            'respiratory_rate' => 'nullable|integer|min:0|max:200',
            'subjective' => 'nullable|string|max:5000',
            'objective' => 'nullable|string|max:5000',
            'assessment' => 'nullable|string|max:5000',
            'plan' => 'nullable|string|max:5000',
            'symptoms' => 'nullable|array|max:50',
            'symptoms.*' => 'string|max:200',
            'diagnosis' => 'nullable|string|max:2000',
            'treatment_plan' => 'nullable|string|max:5000',
            'prescriptions' => 'nullable|array|max:20',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'pet_id' => ['description' => 'ID do pet cujo prontuário está sendo criado.'],
            'appointment_id' => ['description' => 'Agendamento vinculado a este prontuário (opcional).'],
            'record_date' => ['description' => 'Data do atendimento (YYYY-MM-DD).'],
            'weight' => ['description' => 'Peso em kg no momento do atendimento.'],
            'temperature' => ['description' => 'Temperatura em °C.'],
            'subjective' => ['description' => 'Anamnese (S do SOAP).'],
            'objective' => ['description' => 'Achados do exame físico (O do SOAP).'],
            'assessment' => ['description' => 'Avaliação / diagnóstico (A do SOAP).'],
            'plan' => ['description' => 'Plano terapêutico (P do SOAP).'],
        ];
    }
}
