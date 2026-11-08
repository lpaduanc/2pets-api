<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\FiscalDocumentKind;
use App\Services\Fiscal\Calculation\FiscalNumberGaps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inutilização de faixa (spec fase4-fiscal §3.7). Formato aqui; regra da faixa (sobreposição,
 * número usado, ordem) em `FiscalNumberInvalidationService`.
 */
class StoreFiscalNumberInvalidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([FiscalDocumentKind::NFE->value, FiscalDocumentKind::NFCE->value])],
            'number_from' => ['required', 'integer', 'min:1', 'max:999999999'],
            'number_to' => ['required', 'integer', 'min:1', 'max:999999999'],
            'reason' => ['required', 'string', 'min:'.FiscalNumberGaps::REASON_MIN_LENGTH, 'max:'.FiscalNumberGaps::REASON_MAX_LENGTH],
        ];
    }
}
