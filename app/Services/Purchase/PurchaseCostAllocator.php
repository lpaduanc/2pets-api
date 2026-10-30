<?php

namespace App\Services\Purchase;

use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * Custo de aquisição real de cada item da compra — docs/gap-petflow/PLANO.md, 1.2 (a conta da
 * linha está em `PurchaseCostLine`). Classe pura (sem banco): é a MESMA conta da prévia
 * (`POST purchases/cost-preview`), da conferência do XML e do salvamento — a tela nunca mostra
 * um número e grava outro.
 *
 * Despesa de cabeçalho (frete, seguro, outras) que a nota não detalha por item é rateada pelo
 * maior resto (`Money::allocate`) com peso = valor da linha − desconto do item: nenhum centavo
 * some nem aparece. Quando QUALQUER item traz a parte explícita de um tipo (XML com `det/prod/
 * vFrete`), aquele tipo não é rateado de novo, e o total do cabeçalho tem de bater com a soma.
 *
 * Regime tributário: no Simples Nacional nada é creditado (IPI e ST entram no custo). Crédito de
 * Lucro Presumido/Real está fora do escopo do plano.
 */
final class PurchaseCostAllocator
{
    /** Tipo de despesa rateável → campo do total no cabeçalho. */
    public const SHARE_KINDS = [
        'freight_share' => 'total_freight',
        'insurance_share' => 'total_insurance',
        'other_share' => 'total_other',
    ];

    /**
     * @param  array<string, mixed>  $header  totais do cabeçalho (`total_freight`, `total_insurance`, `total_other`, `total_discount`)
     * @param  list<array<string, mixed>>  $items  quantity, unit_cost, gross_total?, discount?, *_share?, ipi_amount?, icms_st_amount?, conversion_factor?
     * @return array{items: list<array<string, string>>, totals: array<string, string>}
     */
    public function allocate(array $header, array $items): array
    {
        $items = array_values($items);
        $lines = array_map(fn (array $item, int $index): PurchaseCostLine => PurchaseCostLine::fromInput($item, $index), $items, array_keys($items));
        $lines = $this->applyDiscount($lines, $header['total_discount'] ?? null);

        foreach (self::SHARE_KINDS as $kind => $totalKey) {
            $lines = $this->applyShare($lines, $items, $kind, $header[$totalKey] ?? null);
        }

        return [
            'items' => array_map(fn (PurchaseCostLine $line): array => $line->toArray(), $lines),
            'totals' => $this->totals($lines),
        ];
    }

    /**
     * Desconto do cabeçalho: vazio/zero ou igual à soma dos itens é só conferência (NF-e: vDesc
     * total = Σ vDesc dos itens). Itens sem desconto e cabeçalho com valor: rateado pelo valor
     * da linha. Qualquer outra combinação somaria o desconto duas vezes — recusada.
     *
     * @param  list<PurchaseCostLine>  $lines
     * @return list<PurchaseCostLine>
     */
    private function applyDiscount(array $lines, mixed $headerDiscount): array
    {
        $header = Money::of($headerDiscount ?? 0);

        if (self::sum($lines, PurchaseCostLine::DISCOUNT)->isZero() && $header->isPositive()) {
            $grossWeights = array_map(fn (PurchaseCostLine $line): Money => $line->gross(), $lines);
            $lines = $this->spread($lines, PurchaseCostLine::DISCOUNT, $header, $grossWeights);
        }

        $this->assertHeaderMatches('total_discount', $header->isZero() ? null : $header, self::sum($lines, PurchaseCostLine::DISCOUNT));
        $this->assertDiscountsFitLines($lines);

        return $lines;
    }

    /**
     * @param  list<PurchaseCostLine>  $lines
     * @param  list<array<string, mixed>>  $items
     * @return list<PurchaseCostLine>
     */
    private function applyShare(array $lines, array $items, string $kind, mixed $headerTotal): array
    {
        $header = $headerTotal === null ? null : Money::of($headerTotal);
        $isExplicit = collect($items)->contains(fn (array $item): bool => isset($item[$kind]));

        if (! $isExplicit) {
            $weights = array_map(fn (PurchaseCostLine $line): Money => $line->net(), $lines);

            return $this->spread($lines, $kind, $header ?? Money::zero(), $weights);
        }

        // Parte explícita já veio na linha (`PurchaseCostLine::fromInput`; ausente = zero).
        $this->assertHeaderMatches(self::SHARE_KINDS[$kind], $header, self::sum($lines, $kind));

        return $lines;
    }

    /**
     * @param  list<PurchaseCostLine>  $lines
     * @param  list<Money>  $weights
     * @return list<PurchaseCostLine>
     */
    private function spread(array $lines, string $field, Money $total, array $weights): array
    {
        foreach ($total->allocate($weights) as $index => $share) {
            $lines[$index] = $lines[$index]->with($field, $share);
        }

        return $lines;
    }

    private function assertHeaderMatches(string $field, ?Money $header, Money $itemsSum): void
    {
        if ($header === null || $header->isEqualTo($itemsSum)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => sprintf('O total do cabeçalho (%s) difere da soma dos itens (%s).', $header->toString(), $itemsSum->toString()),
        ]);
    }

    /** @param  list<PurchaseCostLine>  $lines */
    private function assertDiscountsFitLines(array $lines): void
    {
        foreach ($lines as $index => $line) {
            if ($line->net()->isNegative()) {
                throw ValidationException::withMessages([
                    "items.{$index}.discount" => sprintf('Item %d: desconto maior que o valor da linha.', $index + 1),
                ]);
            }
        }
    }

    /**
     * Total da compra = produtos − desconto + frete + seguro + outras + IPI + ST (o vNF da NF-e).
     *
     * @param  list<PurchaseCostLine>  $lines
     * @return array<string, string>
     */
    private function totals(array $lines): array
    {
        $totals = [
            'total_products' => Money::sum(array_map(fn (PurchaseCostLine $line): Money => $line->gross(), $lines)),
            'total_discount' => self::sum($lines, PurchaseCostLine::DISCOUNT),
            'total_freight' => self::sum($lines, 'freight_share'),
            'total_insurance' => self::sum($lines, 'insurance_share'),
            'total_other' => self::sum($lines, 'other_share'),
            'total_ipi' => self::sum($lines, 'ipi_amount'),
            'total_icms_st' => self::sum($lines, 'icms_st_amount'),
        ];
        $totals['total'] = Money::sum(array_slice($totals, 2))->plus($totals['total_products'])->minus($totals['total_discount']);

        return array_map(fn (Money $amount): string => $amount->toString(), $totals);
    }

    /** @param  list<PurchaseCostLine>  $lines */
    private static function sum(array $lines, string $field): Money
    {
        return Money::sum(array_map(fn (PurchaseCostLine $line): Money => $line->amount($field), $lines));
    }
}
