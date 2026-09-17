<?php

namespace App\Http\Requests\MedicalRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST professional/medical-records/{id}/finalize` — contrato §3.
 *
 * A regra "diagnóstico OU plano de tratamento, e peso" NÃO é validada aqui: esses campos já
 * foram salvos via `PUT` (o rascunho), então a checagem é contra o ESTADO PERSISTIDO do
 * prontuário, não contra o corpo desta requisição — que só carrega o retorno opcional. Ver
 * `MedicalRecordFinalizationService::assertCanFinalize()`.
 */
class FinalizeMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'follow_up' => ['nullable', 'array'],
            'follow_up.date' => ['required_with:follow_up', 'date', 'after:now'],
            'follow_up.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'follow_up.date.required_with' => 'A data do retorno é obrigatória.',
            'follow_up.date.after' => 'A data do retorno deve ser no futuro.',
        ];
    }
}
