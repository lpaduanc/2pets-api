<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET reports/birthdays` — contrato `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`.
 */
class BirthdayPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'in:pets,clients,both'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
