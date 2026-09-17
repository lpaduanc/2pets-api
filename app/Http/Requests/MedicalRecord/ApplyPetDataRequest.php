<?php

namespace App\Http\Requests\MedicalRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /medical-records/{id}/apply-to-pet` — contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D.
 *
 * Autorização real (só o tutor dono do pet) vive em `MedicalRecordPolicy::applyToPet`,
 * verificada no controller — aqui só garante que existe um usuário autenticado.
 */
class ApplyPetDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ];
    }
}
