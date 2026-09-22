<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regime/inscrições da organização emitente — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Certificado A1 NÃO passa por
 * aqui: fluxo de upload fica com o cofre externo (`security-specialist`).
 */
class UpdateFiscalSettingsRequest extends FormRequest
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
            'tax_regime' => ['nullable', Rule::enum(TaxRegime::class)],
            'municipal_registration' => ['nullable', 'string', 'max:30'],
            'state_registration' => ['nullable', 'string', 'max:30'],
            'state_registration_type' => ['nullable', Rule::enum(StateRegistrationType::class)],
            'cnae_code' => ['nullable', 'string', 'max:10'],
            'special_tax_regime' => ['nullable', 'string', 'max:255'],
            'iss_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
