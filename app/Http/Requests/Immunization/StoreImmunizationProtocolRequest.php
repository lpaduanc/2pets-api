<?php

namespace App\Http\Requests\Immunization;

use App\Enums\ImmunizationApplicationMode;
use App\Enums\ImmunizationDoseAnchor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corpo do "construtor de protocolo" — a lista `doses` chega completa (o front monta o grafo
 * inteiro antes de salvar, mesmo padrão de `items` em `StorePrescriptionRequest`).
 * `doses.*.depends_on_dose_number` referencia `dose_number` DENTRO do mesmo payload (não um
 * id de banco, que ainda não existe) — resolvido pelo service ao persistir.
 */
class StoreImmunizationProtocolRequest extends FormRequest
{
    private const MAX_DOSES = 7;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'application_mode' => ['required', Rule::in(ImmunizationApplicationMode::values())],
            'total_doses' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_DOSES,
                Rule::requiredIf(fn () => $this->input('application_mode') === ImmunizationApplicationMode::FIXED_DOSES->value),
            ],
            'active' => ['nullable', 'boolean'],
            'doses' => ['required', 'array', 'min:1', 'max:'.self::MAX_DOSES],
            'doses.*.dose_number' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DOSES],
            'doses.*.interval_days' => ['nullable', 'integer', 'min:0'],
            'doses.*.depends_on_dose_number' => ['nullable', 'integer', 'min:1'],
            'doses.*.anchor' => ['required', Rule::in(ImmunizationDoseAnchor::values())],
            'doses.*.transitions_to_product_id' => ['nullable', 'integer', 'exists:immunization_products,id'],
            'doses.*.min_age_days' => ['nullable', 'integer', 'min:0'],
            'doses.*.max_age_days' => ['nullable', 'integer', 'min:0'],
            'doses.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
