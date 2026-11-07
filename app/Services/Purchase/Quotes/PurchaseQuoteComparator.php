<?php

namespace App\Services\Purchase\Quotes;

use App\Services\Purchase\PurchaseCostAllocator;
use Brick\Math\BigDecimal;

/**
 * Comparativo da cotação (docs/gap-petflow/PLANO.md, 3.5). Classe pura (sem banco).
 *
 * Custo unitário REAL de cada oferta = preço + frete do fornecedor rateado pelos itens que ELE
 * cotou, com a mesma conta do custo real da compra (item 1.2, `PurchaseCostAllocator`): rateio
 * pelo maior resto com peso = valor da linha (quantidade × preço), 4 casas no unitário. Assim o
 * número do comparativo é o mesmo que a entrada da nota vai gravar se o frete vier igual.
 *
 * Menor custo real por item ganha; empate → menor prazo de entrega (sem prazo perde); empate de
 * novo → a ordem das ofertas (quem foi convidado primeiro). Exatamente um `is_lowest` por item
 * cotado.
 */
final class PurchaseQuoteComparator
{
    public function __construct(private readonly PurchaseCostAllocator $allocator) {}

    /**
     * @param  list<array{id: int|string, quantity: string}>  $items
     * @param  list<array{key: int|string, freight: string, delivery_days: int|null, prices: array<int|string, string>}>  $offers
     * @return array{items: array<int|string, array{best: int|string|null, offers: array<int|string, array<string, mixed>>}>, suppliers: array<int|string, array<string, mixed>>}
     */
    public function compare(array $items, array $offers): array
    {
        $result = ['items' => [], 'suppliers' => []];

        foreach ($items as $item) {
            $result['items'][$item['id']] = ['best' => null, 'offers' => []];
        }

        foreach ($offers as $offer) {
            [$costs, $totals] = $this->landed($items, $offer);
            $result['suppliers'][$offer['key']] = ['quoted_items' => count($costs)] + $totals;

            foreach ($costs as $itemId => $cost) {
                $result['items'][$itemId]['offers'][$offer['key']] = [
                    'unit_price' => (string) BigDecimal::of($offer['prices'][$itemId])->toScale(4),
                    'real_unit_cost' => $cost,
                    'delivery_days' => $offer['delivery_days'],
                    'is_lowest' => false,
                ];
            }
        }

        foreach ($result['items'] as $itemId => $row) {
            $best = $this->best($row['offers']);

            if ($best !== null) {
                $result['items'][$itemId]['best'] = $best;
                $result['items'][$itemId]['offers'][$best]['is_lowest'] = true;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{id: int|string, quantity: string}>  $items
     * @param  array{key: int|string, freight: string, delivery_days: int|null, prices: array<int|string, string>}  $offer
     * @return array{0: array<int|string, string>, 1: array{products_total: string, freight: string, total: string}}
     */
    private function landed(array $items, array $offer): array
    {
        $quoted = array_values(array_filter($items, fn (array $item): bool => isset($offer['prices'][$item['id']])));

        if ($quoted === []) {
            return [[], ['products_total' => '0.00', 'freight' => '0.00', 'total' => '0.00']];
        }

        $allocated = $this->allocator->allocate(
            ['total_freight' => $offer['freight']],
            array_map(fn (array $item): array => ['quantity' => $item['quantity'], 'unit_cost' => $offer['prices'][$item['id']]], $quoted),
        );
        $costs = [];

        foreach ($quoted as $index => $item) {
            $costs[$item['id']] = $allocated['items'][$index]['landed_unit_cost'];
        }

        $totals = $allocated['totals'];

        return [$costs, ['products_total' => $totals['total_products'], 'freight' => $totals['total_freight'], 'total' => $totals['total']]];
    }

    /** @param  array<int|string, array<string, mixed>>  $offers */
    private function best(array $offers): int|string|null
    {
        $best = null;

        foreach ($offers as $key => $offer) {
            if ($best === null || $this->beats($offer, $offers[$best])) {
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function beats(array $candidate, array $current): bool
    {
        $byCost = BigDecimal::of($candidate['real_unit_cost'])->compareTo($current['real_unit_cost']);

        if ($byCost !== 0) {
            return $byCost < 0;
        }

        return ($candidate['delivery_days'] ?? PHP_INT_MAX) < ($current['delivery_days'] ?? PHP_INT_MAX);
    }
}
