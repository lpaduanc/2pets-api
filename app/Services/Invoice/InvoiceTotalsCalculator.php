<?php

namespace App\Services\Invoice;

/**
 * Autoridade única sobre os totais de uma fatura. `InvoiceController` nunca deve confiar em
 * `subtotal`/`total`/`items.*.total` mandados pelo cliente — este cálculo é sempre refeito no
 * servidor a partir de `items` (quantidade × preço unitário) + `discount`/`tax`.
 */
final class InvoiceTotalsCalculator
{
    private const DECIMAL_PRECISION = 2;

    /**
     * @param  array<int, array{description: string, quantity: float|int|string, unit_price: float|int|string}>  $items
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, total: float}
     */
    public function recalculate(array $items, float $discount, float $tax): array
    {
        $itemsWithTotals = $this->calculateItemTotals($items);
        $subtotal = $this->sumItemTotals($itemsWithTotals);

        return [
            'items' => $itemsWithTotals,
            'subtotal' => $subtotal,
            'total' => $this->calculateTotal($subtotal, $tax, $discount),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function calculateItemTotals(array $items): array
    {
        return array_map(fn (array $item): array => [
            ...$item,
            'total' => $this->roundMoney((float) $item['quantity'] * (float) $item['unit_price']),
        ], $items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function sumItemTotals(array $items): float
    {
        return $this->roundMoney(array_sum(array_column($items, 'total')));
    }

    private function calculateTotal(float $subtotal, float $tax, float $discount): float
    {
        return max(0.0, $this->roundMoney($subtotal + $tax - $discount));
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, self::DECIMAL_PRECISION);
    }
}
