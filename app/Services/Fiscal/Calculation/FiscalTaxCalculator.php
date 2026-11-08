<?php

namespace App\Services\Fiscal\Calculation;

use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\Support\Fiscal\FiscalTaxCodes;
use App\Support\Money\Decimal;
use App\Support\Money\Money;

/**
 * Conta do item fiscal — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.1/§3.2. Classe
 * PURA (sem banco), lida pelo golden `tests/Fixtures/golden/fiscal.json`.
 *
 * - Desconto do cabeçalho e acréscimo (vOutro) rateados por `Money::allocate` (maior resto): Σ
 *   itens = total do documento, centavo a centavo.
 * - ICMS só para os códigos que destacam (`FiscalTaxCodes`); base = total × (1 − redução).
 * - PIS/COFINS sobre o total MENOS o ICMS destacado (STF Tema 69; decisão D9 da spec — confirmar
 *   com o contador). Arredondamento HALF_UP uma vez por imposto por item.
 */
final class FiscalTaxCalculator
{
    /** Alíquota e percentual com 4 casas (PLANO §3, item 2). */
    public const RATE_SCALE = 4;

    private const FULL_BASE_PERCENT = 100;

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, array{gross: Money, discount: Money}>  $lines  desconto = o da própria linha
     * @return array<TKey, array{gross: Money, discount: Money, net: Money, surcharge: Money, total: Money}>
     */
    public function distribute(array $lines, Money $headerDiscount, Money $surcharge): array
    {
        $ownNet = array_map(fn (array $line): Money => $line['gross']->minus($line['discount']), $lines);
        $discountShares = $this->allocate($headerDiscount, $ownNet);
        $net = array_map(fn (Money $value, Money $share): Money => $value->minus($share), $ownNet, $discountShares);
        $net = array_combine(array_keys($lines), $net);
        $surchargeShares = array_combine(array_keys($lines), $this->allocate($surcharge, $net));

        $result = [];

        foreach ($lines as $key => $line) {
            $result[$key] = [
                'gross' => $line['gross'],
                'discount' => $line['gross']->minus($net[$key]),
                'net' => $net[$key],
                'surcharge' => $surchargeShares[$key],
                'total' => $net[$key]->plus($surchargeShares[$key]),
            ];
        }

        return $result;
    }

    /**
     * Impostos de um item sobre o total dele (líquido + acréscimo). Sem regra (serviço/NFS-e), tudo
     * zero.
     *
     * @return array<string, string>
     */
    public function taxes(Money $total, ?FiscalTaxRule $rule): array
    {
        $icms = $this->icms($total, $rule);
        $contributionBase = $total->minus($icms['icms_amount']);

        return $this->stringify($icms + [
            'icms_sn_credit_rate' => $this->snCreditRate($rule),
            'icms_sn_credit_amount' => $total->percentage($this->snCreditRate($rule)),
        ] + $this->contribution('pis', $contributionBase, $rule?->pisRate ?? '0')
          + $this->contribution('cofins', $contributionBase, $rule?->cofinsRate ?? '0'));
    }

    /** @return array<string, Money|string> */
    private function icms(Money $total, ?FiscalTaxRule $rule): array
    {
        if ($rule === null || ! FiscalTaxCodes::highlightsIcms($rule->cst, $rule->csosn)) {
            return ['icms_base' => Money::zero(), 'icms_rate' => '0', 'icms_amount' => Money::zero(), 'fcp_rate' => '0', 'fcp_amount' => Money::zero()];
        }

        $base = $total->percentage(Decimal::of(self::FULL_BASE_PERCENT)->minus(Decimal::of($rule->icmsBaseReductionPercent)));

        return [
            'icms_base' => $base,
            'icms_rate' => $rule->icmsRate,
            'icms_amount' => $base->percentage($rule->icmsRate),
            'fcp_rate' => $rule->fcpRate,
            'fcp_amount' => $base->percentage($rule->fcpRate),
        ];
    }

    private function snCreditRate(?FiscalTaxRule $rule): string
    {
        return $rule !== null && FiscalTaxCodes::informsSimplesCredit($rule->csosn) ? $rule->snCreditRate : '0';
    }

    /** @return array<string, Money|string> */
    private function contribution(string $tax, Money $base, string $rate): array
    {
        if (! Decimal::of($rate)->isPositive()) {
            return ["{$tax}_base" => Money::zero(), "{$tax}_rate" => '0', "{$tax}_amount" => Money::zero()];
        }

        return ["{$tax}_base" => $base, "{$tax}_rate" => $rate, "{$tax}_amount" => $base->percentage($rate)];
    }

    /**
     * Rateio que devolve zeros quando não há o que ratear (sem cair na divisão em partes iguais
     * do `allocate` com pesos zero).
     *
     * @param  array<array-key, Money>  $weights
     * @return list<Money>
     */
    private function allocate(Money $amount, array $weights): array
    {
        if ($amount->isZero() || $weights === []) {
            return array_values(array_map(fn (): Money => Money::zero(), $weights));
        }

        return array_values($amount->allocate($weights));
    }

    /**
     * Dinheiro com 2 casas, alíquota com 4 (PLANO §3).
     *
     * @param  array<string, Money|string>  $values
     * @return array<string, string>
     */
    private function stringify(array $values): array
    {
        return array_map(
            fn (Money|string $value): string => $value instanceof Money ? $value->toString() : (string) Decimal::round($value, self::RATE_SCALE),
            $values,
        );
    }
}
