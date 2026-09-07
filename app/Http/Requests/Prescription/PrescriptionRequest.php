<?php

namespace App\Http\Requests\Prescription;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regras comuns a criar e editar prescrição.
 *
 * O shape de cada medicamento é validado item a item (`medications.*.campo`) porque a tela monta
 * uma linha por medicamento e mapeia o erro 422 pelo índice — `medications.0.name` precisa
 * chegar como chave, que é exatamente o formato padrão do Laravel.
 *
 * Autorização NÃO mora aqui: quem decide se este profissional pode prescrever para este pet é
 * `AuthorizesPetAccess::resolvePetForWrite()` (gate de privacidade do pet, com PetVetAccess).
 * O `authorize()` só barra requisição sem usuário autenticado.
 */
abstract class PrescriptionRequest extends FormRequest
{
    /** Teto defensivo: nenhuma receita real tem tantos itens, e sem limite o payload é ilimitado. */
    protected const MAX_MEDICATIONS = 50;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, string>
     */
    protected function medicationRules(): array
    {
        return [
            'medications.*' => 'array',
            'medications.*.name' => 'required|string|max:255',
            'medications.*.dosage' => 'required|string|max:100',
            'medications.*.frequency' => 'required|string|max:100',
            'medications.*.duration' => 'nullable|string|max:100',
            'medications.*.instructions' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function contentRules(): array
    {
        return [
            'general_instructions' => 'nullable|string|max:1000',
            'warnings' => 'nullable|string|max:1000',
            'is_controlled' => 'sometimes|boolean',
            'appointment_id' => 'nullable|exists:appointments,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pet_id' => 'pet',
            'prescription_date' => 'data da prescrição',
            'valid_until' => 'validade',
            'medications' => 'medicamentos',
            'general_instructions' => 'orientações gerais',
            'warnings' => 'advertências',
        ];
    }
}
