<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\CardFeeReferenceMode;
use App\Enums\CardSurchargeReturnPolicy;
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
            // Opcionais no PUT, mas nunca nulos: a coluna não aceita "sem escolha" — o padrão
            // já vale desde a criação da organização.
            'card_surcharge_on_invoice' => ['sometimes', 'boolean'],
            'card_surcharge_return_policy' => ['sometimes', Rule::enum(CardSurchargeReturnPolicy::class)],
            // Precificação (PLANO 1.4). Alíquota efetiva do Simples informada pela clínica, em %
            // com até 4 casas; `null` = não informada (o preço mínimo usa 0 e avisa).
            'effective_sales_tax_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'card_fee_reference_mode' => ['sometimes', Rule::enum(CardFeeReferenceMode::class)],
        ];
    }
}
