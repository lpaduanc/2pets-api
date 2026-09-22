<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET reports/immunization` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`.
 */
class ImmunizationPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:vaccine,deworming'],
            'status' => ['sometimes', 'in:overdue,due_soon,upcoming,applied'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
