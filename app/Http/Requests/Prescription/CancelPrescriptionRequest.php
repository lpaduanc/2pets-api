<?php

namespace App\Http\Requests\Prescription;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST prescriptions/{id}/cancel` — contrato docs/atendimento-veterinario/03-contrato-receituario.md
 * §1. `reason` é sempre obrigatório: cancelar sem motivo não deixa rastro auditável do porquê.
 */
class CancelPrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function bodyParameters(): array
    {
        return [
            'reason' => ['description' => 'Motivo do cancelamento — obrigatório.'],
        ];
    }
}
