<?php

namespace App\Http\Requests\Prescription;

class StorePrescriptionRequest extends PrescriptionRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_merge([
            'pet_id' => 'required|exists:pets,id',
            'prescription_date' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:prescription_date',
            'medications' => 'required|array|min:1|max:'.self::MAX_MEDICATIONS,
        ], $this->medicationRules(), $this->contentRules());
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'pet_id' => ['description' => 'ID do pet que recebe a prescrição. Exige acesso de escrita ao pet.'],
            'prescription_date' => ['description' => 'Data da prescrição (YYYY-MM-DD).'],
            'valid_until' => ['description' => 'Data limite de validade (YYYY-MM-DD). Nunca anterior à data da prescrição.'],
            'medications' => ['description' => 'Lista de medicamentos prescritos.'],
            'medications.*.name' => ['description' => 'Nome do medicamento.'],
            'medications.*.dosage' => ['description' => 'Dosagem (ex.: 250mg).'],
            'medications.*.frequency' => ['description' => 'Frequência (ex.: 2x ao dia).'],
            'medications.*.duration' => ['description' => 'Duração do tratamento (ex.: 7 dias).'],
            'medications.*.instructions' => ['description' => 'Orientação específica deste medicamento.'],
            'is_controlled' => ['description' => 'Marca a receita como de medicamento controlado.'],
        ];
    }
}
