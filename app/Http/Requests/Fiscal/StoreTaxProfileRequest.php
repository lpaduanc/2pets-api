<?php

namespace App\Http\Requests\Fiscal;

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\GoodsOrigin;
use App\Enums\TaxRegime;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Fiscal\FiscalTaxCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Perfil tributário (store e update) — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §2.
 * O código de ICMS exigido depende do regime da organização: CSOSN no Simples/MEI, CST no
 * Presumido/Real. Regime ainda não informado: nenhum dos dois é obrigatório aqui (a emissão
 * recusa depois, pedindo o regime). Alíquotas e percentuais com até 4 casas.
 */
class StoreTaxProfileRequest extends FormRequest
{
    private const RATE_RULES = ['numeric', 'decimal:0,4', 'min:0', 'max:100'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $regime = $this->regime();

        return [
            'name' => [$required, 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'origin' => [$required, Rule::enum(GoodsOrigin::class)],
            'icms_csosn' => [Rule::requiredIf(fn (): bool => $this->demands('icms_csosn', $regime?->usesCsosn() === true)), 'nullable', Rule::in(FiscalTaxCodes::CSOSN)],
            'icms_cst' => [Rule::requiredIf(fn (): bool => $this->demands('icms_cst', $regime !== null && ! $regime->usesCsosn())), 'nullable', Rule::in(FiscalTaxCodes::ICMS_CST)],
            'icms_rate' => ['sometimes', ...self::RATE_RULES],
            'icms_base_reduction_percent' => ['sometimes', ...self::RATE_RULES],
            'icms_sn_credit_rate' => ['sometimes', ...self::RATE_RULES],
            'fcp_rate' => ['sometimes', ...self::RATE_RULES],
            'pis_cst' => ['sometimes', 'nullable', Rule::in(FiscalTaxCodes::PIS_COFINS_CST)],
            'pis_rate' => ['sometimes', ...self::RATE_RULES],
            'cofins_cst' => ['sometimes', 'nullable', Rule::in(FiscalTaxCodes::PIS_COFINS_CST)],
            'cofins_rate' => ['sometimes', ...self::RATE_RULES],
            'benefit_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'cfops' => ['sometimes', 'array'],
            'cfops.*.operation' => ['required', Rule::enum(FiscalCfopOperation::class)],
            'cfops.*.scope' => ['required', Rule::enum(FiscalCfopScope::class)],
            'cfops.*.cfop' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /** CFOP com o primeiro dígito da direção (entrada 1/2, saída 5/6) e sem faixa repetida. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];

            foreach ((array) $this->input('cfops', []) as $index => $cfop) {
                $operation = FiscalCfopOperation::tryFrom((string) ($cfop['operation'] ?? ''));
                $scope = FiscalCfopScope::tryFrom((string) ($cfop['scope'] ?? ''));
                $this->checkCfop($validator, (int) $index, [$operation, $scope, (string) ($cfop['cfop'] ?? '')], $seen);
            }
        }];
    }

    /**
     * @param  array{0: FiscalCfopOperation|null, 1: FiscalCfopScope|null, 2: string}  $entry
     * @param  array<string, true>  $seen
     */
    private function checkCfop(Validator $validator, int $index, array $entry, array &$seen): void
    {
        [$operation, $scope, $cfop] = $entry;

        if ($operation === null || $scope === null) {
            return;
        }

        $key = $operation->value.'|'.$scope->value;
        $expected = $operation->expectedFirstDigit($scope);

        if (isset($seen[$key])) {
            $validator->errors()->add("cfops.{$index}.operation", 'Faixa de CFOP repetida.');
        }

        if ($cfop !== '' && $cfop[0] !== $expected) {
            $validator->errors()->add("cfops.{$index}.cfop", sprintf('CFOP de %s deve começar com %s.', mb_strtolower($operation->label()), $expected));
        }

        $seen[$key] = true;
    }

    /** No POST o regime exige o código; no PUT só se o campo veio (não apaga o que já existe). */
    private function demands(string $field, bool $regimeRequires): bool
    {
        return $regimeRequires && ($this->isMethod('POST') || $this->exists($field));
    }

    private function regime(): ?TaxRegime
    {
        $organization = app(CommercialScopeResolver::class)->primaryOrganization($this->user());

        return $organization?->tax_regime ?? $this->user()?->tax_regime;
    }
}
