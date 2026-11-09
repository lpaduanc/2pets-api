<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\CardFeeReferenceMode;
use App\Enums\CardSurchargeReturnPolicy;
use App\Enums\FiscalEnvironment;
use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regime/inscrições da organização emitente — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Certificado A1 e CSC NÃO passam
 * por aqui: têm endpoints próprios (spec fase4-transmissao-propria-nfe-nfce §3/§4).
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
            // Crediário (PLANO 2.2): multa ≤ 2% (CDC art. 52 §1º), juros de mora ≤ 1% a.m.
            // (CC art. 406 c/c CTN art. 161 §1º), carência em dias.
            'credit_late_fee_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:2'],
            'credit_monthly_interest_percent' => ['sometimes', 'numeric', 'decimal:0,4', 'min:0', 'max:1'],
            'credit_grace_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
            // Fase 4 fiscal: ambiente (numeração separada por ambiente), série e próximo número por
            // modelo — quem vem de outro sistema continua a numeração de lá.
            'fiscal_environment' => ['sometimes', Rule::enum(FiscalEnvironment::class)],
            'fiscal_series' => ['sometimes', 'array:nfe,nfce,nfse'],
            'fiscal_series.*.series' => ['sometimes', 'string', 'regex:/^\d{1,3}$/'],
            // Série da DPS (NFS-e nacional): faixa oficial do aplicativo próprio começa em 00001 —
            // zero é recusado pela Sefin (E0010).
            'fiscal_series.nfse.series' => ['sometimes', 'string', 'regex:/^0{0,2}[1-9]\d{0,2}$/'],
            'fiscal_series.*.next_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            // cMun do emitente (7 dígitos do IBGE) — só quando o nome da cidade não bate com a tabela.
            'city_ibge_code' => ['sometimes', 'nullable', 'digits:7'],
        ];
    }
}
